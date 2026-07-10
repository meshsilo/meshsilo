# Streaming STL-to-3MF Converter Implementation Plan

Created: 2026-05-03
Author: jtgraham@centnetwork.us
Status: VERIFIED
Approved: Yes
Iterations: 0
Worktree: No
Type: Feature

## Summary

**Goal:** Rewrite the STL-to-3MF converter to use disk-backed processing (SQLite temp DB for vertex deduplication + binary temp file for triangle indices) instead of PHP arrays, enabling conversion of arbitrarily large STL files (43M+ triangles) within bounded RAM (~100-200MB).

**Architecture:** Replace `$this->vertices`, `$this->triangles`, and `$vertexMap` PHP arrays with a SQLite temp database for vertex storage/dedup and a binary temp file for triangle indices. Parsing writes to disk; 3MF generation reads sequentially from disk. The public API (`convertTo3MF`, `estimateConversion`, `parseSTL`) remains unchanged.

**Tech Stack:** PHP 8.1, SQLite (PDO, temp database), ZipArchive

## Scope

### In Scope

- Rewrite `parseBinarySTL()` and `parseASCIISTL()` to use disk-backed storage
- Rewrite `write3MFModelToFile()` to read from SQLite/temp file instead of PHP arrays
- Rewrite `estimateConversion()` ASCII path to stream-count without loading into memory
- Remove `getMaxTriangles()`, `getMemoryLimitBytes()`, and `BYTES_PER_TRIANGLE` (no longer needed — disk-backed has no triangle ceiling)
- Remove `ini_set('memory_limit', '8G')` in `convertPartTo3MF()` (no longer needed)
- Add unit tests for the converter
- Update `convertTo3MF()` return values to still include vertex/triangle counts

### Out of Scope

- Changing the public API signatures
- Changing the job queue system or `ConvertStlTo3mf` job class
- Changing the `convert-part.php` action handler
- Performance optimization beyond bounded memory (e.g., parallel processing)
- Other file format conversions

## Approach

**Chosen:** SQLite temp DB for vertex dedup + binary temp file for triangle indices

**Why:** SQLite provides indexed B-tree lookups for vertex deduplication with predictable memory usage (~50-100MB page cache). Binary temp files give optimal sequential I/O for triangle data that only needs sequential access. Both are cleaned up automatically. This gives bounded memory regardless of input size, at the cost of slower I/O compared to in-memory arrays.

**Alternatives considered:**
- **Binary temp file with custom hash index** — faster raw I/O but requires implementing collision handling and file-based hash table. More code, more bugs.
- **LevelDB/RocksDB via FFI** — fastest for random lookups but adds external dependency not available in standard PHP Docker images.
- **Hybrid (in-memory below threshold, disk above)** — rejected; user chose consistent bounded memory over speed for small files.

## Context for Implementer

> The converter lives entirely in `includes/converter.php`. It's used via two wrapper functions at the bottom of the file (`convertPartTo3MF` and `estimatePartConversion`) which are called from `jobs/ConvertStlTo3mf.php` (queue worker) and `app/actions/convert-part.php` (AJAX endpoint).

- **Patterns to follow:** The existing `write3MFModelToFile()` already streams output in 1000-line batches with `fwrite` — keep that pattern for the 3MF output phase
- **Conventions:** The class uses `private` for internal methods, public for API methods. Wrapper functions outside the class handle DB updates and file management.
- **Key files:**
  - `includes/converter.php` — the entire converter (STLConverter class + wrapper functions)
  - `jobs/ConvertStlTo3mf.php` — queue job that calls `convertPartTo3MF()`
  - `app/actions/convert-part.php` — AJAX endpoint for estimate/convert/batch actions
- **Gotchas:**
  - `isBinarySTL()` (converter.php:18) must NOT be changed — it's used by both parse methods and estimate
  - `getSystemMemory()` (converter.php:488) and `convertPartTo3MF()` (converter.php:546) are standalone functions outside the class — only the class internals change
  - The `convertTo3MF()` return array includes `vertices` and `triangles` counts — these must still be returned even though data is on disk
  - `write3MFModelToFile()` currently `unset()`s array entries as it writes them to free memory progressively — the streaming version reads from disk so this pattern is replaced
- **Domain context:** STL files store triangulated 3D geometry. Binary STL = 80-byte header + 4-byte triangle count + 50 bytes per triangle (normal + 3 vertices + attribute). ASCII STL = text with `vertex x y z` lines. Vertex deduplication maps identical (x,y,z) coordinates to a single index — critical for 3MF which uses indexed vertices.

## Assumptions

- PHP's SQLite PDO driver is always available (it's compiled in by default and used throughout Silo) — all tasks depend on this
- The storage/cache directory is writable for temp files (already assumed by current code at converter.php:382) — Tasks 1, 2, 3 depend on this
- Rounding vertices to 6 decimal places (current behavior at converter.php:161) is sufficient precision for dedup — Task 1 depends on this
- Sequential triangle access is sufficient for 3MF output (no random access needed) — Task 1 depends on this

## Risks and Mitigations

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| SQLite INSERT performance too slow for 43M triangles | Medium | High | Use WAL journal mode, batch INSERTs in transactions of 50K, use prepared statements. Benchmark during implementation. |
| Temp files not cleaned up on crash | Low | Medium | Use `try/finally` blocks and `register_shutdown_function` to ensure cleanup. Place temp files in `storage/cache/` which has periodic cleanup. |
| Disk space insufficient for temp data | Low | High | 43M triangles = ~500MB temp file + ~2GB SQLite DB. Check available disk space before starting and throw a clear error. |
| Vertex dedup precision issues with BLOB key | Low | Medium | Use the same `pack('d3', $x, $y, $z)` binary key as current code — identical dedup behavior. |

## Goal Verification

### Truths

1. A 43M+ triangle STL file can be converted to 3MF without exceeding 200MB PHP memory usage
2. Small STL files (< 1M triangles) still produce identical 3MF output as before
3. The `convertTo3MF()` return array contains correct vertex/triangle counts
4. `estimateConversion()` for ASCII STL files no longer loads all geometry into memory
5. All temp files (SQLite DB + binary triangle file) are cleaned up after conversion
6. The public API (`convertTo3MF`, `estimateConversion`, `parseSTL`) signatures are unchanged

### Artifacts

1. `includes/converter.php` — rewritten STLConverter class with disk-backed storage
2. `tests/ConverterTest.php` — unit tests covering binary/ASCII parsing, 3MF output, cleanup

## Progress Tracking

- [x] Task 1: Implement disk-backed STLConverter internals
- [x] Task 2: Rewrite 3MF output to read from disk
- [x] Task 3: Rewrite estimateConversion for streaming
- [x] Task 4: Clean up memory-limit code and update wrapper
- [x] Task 5: Add unit tests

**Total Tasks:** 5 | **Completed:** 5 | **Remaining:** 0

## Implementation Tasks

### Task 1: Implement disk-backed STL parsing

**Objective:** Replace `$this->vertices`, `$this->triangles`, and `$vertexMap` PHP arrays with a SQLite temp database for vertices and a binary temp file for triangle indices. Both `parseBinarySTL()` and `parseASCIISTL()` write to disk instead of memory.

**Dependencies:** None

**Files:**

- Modify: `includes/converter.php`

**Key Decisions / Notes:**

- Add private properties: `$tempDb` (PDO), `$triangleFile` (file handle), `$triangleTempPath` (string), `$vertexCount` (int), `$triangleCount` (int)
- Remove: `$vertices = []` and `$triangles = []` properties
- Fix: `convertTo3MF()` at converter.php:371 checks `empty($this->triangles)` — replace with `if ($this->triangleCount === 0)` since the array no longer exists
- SQLite temp DB schema:
  ```sql
  CREATE TABLE vertices (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      key BLOB UNIQUE,
      x REAL,
      y REAL,
      z REAL
  )
  ```
- Vertex insertion pattern: `INSERT OR IGNORE INTO vertices(key,x,y,z) VALUES(?,?,?,?)` then `SELECT id FROM vertices WHERE key = ?` to get the id (works for both new and existing/duplicate vertices). Use two prepared statements cached on the instance. Benchmark with a 1M-triangle synthetic file during implementation — if INSERT+SELECT throughput is below ~100K ops/sec, consider alternative patterns.
- Binary triangle file: write `pack('VVV', $v1, $v2, $v3)` (12 bytes per triangle) — sequential only
- Batch INSERTs in explicit transactions of 50K triangles for performance
- Use `PRAGMA journal_mode=WAL; PRAGMA synchronous=OFF; PRAGMA temp_store=FILE;` for max insert throughput (data is throwaway — no durability needed)
- Init method `initTempStorage()` creates SQLite DB + triangle temp file; `cleanupTempStorage()` closes and deletes both
- Keep `isBinarySTL()` completely unchanged
- Keep the same `pack('d3', $x, $y, $z)` binary key and `round($val, 6)` precision as current code (converter.php:161-165)
- Add `register_shutdown_function` in `initTempStorage()` to ensure cleanup on fatal errors

**Definition of Done:**

- [ ] `parseBinarySTL()` writes vertices to SQLite and triangles to temp file
- [ ] `parseASCIISTL()` writes vertices to SQLite and triangles to temp file
- [ ] PHP memory usage stays under 200MB regardless of input triangle count
- [ ] `$this->vertices` and `$this->triangles` arrays no longer exist
- [ ] Parse results return correct vertex and triangle counts
- [ ] Temp storage is initialized before parsing and cleaned up via `finally` blocks

**Verify:**

- Manual: create a test STL, convert, check output

---

### Task 2: Rewrite 3MF output to read from disk

**Objective:** Rewrite `write3MFModelToFile()` to read vertices from the SQLite DB and triangles from the binary temp file, streaming to the 3MF XML output in batches.

**Dependencies:** Task 1

**Files:**

- Modify: `includes/converter.php`

**Key Decisions / Notes:**

- Vertices: `SELECT id, x, y, z FROM vertices ORDER BY id` — iterate with `PDOStatement::fetch()` in a loop, buffer 1000 lines per `fwrite` (same pattern as current code at converter.php:286-299)
- Triangles: `fopen` the binary temp file, read 12 bytes at a time (`unpack('V3', fread($handle, 12))`), buffer 1000 lines per `fwrite`
- The vertex SELECT must be ORDER BY id to maintain correct index mapping for triangle references
- After writing 3MF, call `cleanupTempStorage()` to free disk space before ZIP creation
- `convertTo3MF()` (converter.php:360) flow stays the same: parseSTL → write3MFModelToFile → ZIP. The only change is internal data source.

**Definition of Done:**

- [ ] `write3MFModelToFile()` reads from SQLite + temp file instead of PHP arrays
- [ ] 3MF output is semantically identical for the same input STL (same vertices, same triangle index mapping, same counts — test by parsing XML, not byte comparison)
- [ ] Buffered writes in batches of 1000 (consistent with current pattern)
- [ ] Temp storage cleaned up after 3MF model file is written

**Verify:**

- Convert a known STL file before and after the change, compare 3MF contents

---

### Task 3: Rewrite estimateConversion for streaming ASCII

**Objective:** Rewrite the ASCII STL path in `estimateConversion()` to stream-count triangles and vertices without loading geometry into memory.

**Dependencies:** None (independent of Tasks 1-2)

**Files:**

- Modify: `includes/converter.php`

**Key Decisions / Notes:**

- Binary STL estimate (converter.php:434-443) already reads only the header — no change needed
- ASCII STL estimate currently calls `$this->parseASCIISTL()` (converter.php:446) which loads everything into memory
- Replace with a simple line-by-line counter: count `endloop` lines = triangle count, count `vertex` lines = total vertex references, estimate unique vertices at 50% sharing (same heuristic as binary path at converter.php:443)
- This means ASCII estimates become approximate (like binary estimates already are) — acceptable for an estimate function
- No temp storage needed for estimates

**Definition of Done:**

- [ ] ASCII STL estimate no longer calls `parseASCIISTL()`
- [ ] ASCII STL estimate reads file line-by-line, counting triangles and vertex references
- [ ] Memory usage for ASCII STL estimate is bounded regardless of file size
- [ ] Estimate results are reasonable (within 20% of actual for typical models)

**Verify:**

- `php -r "require 'includes/converter.php'; $c = new STLConverter(); var_dump($c->estimateConversion('test.stl'));"`

---

### Task 4: Clean up memory-limit code and update wrapper

**Objective:** Remove the memory-based triangle ceiling and `ini_set('memory_limit')` since disk-backed conversion has no memory-proportional limit.

**Dependencies:** Tasks 1, 2

**Files:**

- Modify: `includes/converter.php`

**Key Decisions / Notes:**

- Remove: `BYTES_PER_TRIANGLE` constant, `getMaxTriangles()` method, `getMemoryLimitBytes()` method
- Remove: `ini_set('memory_limit', '8G')` in `convertPartTo3MF()` (converter.php:591-593) — no longer needed since memory is bounded
- Remove: triangle count checks against `$maxTriangles` in `parseBinarySTL()` (converter.php:123-131) and `parseASCIISTL()` (converter.php:234-240)
- Keep: `getSystemMemory()` function and the 90% system memory pre-check in `convertPartTo3MF()` (converter.php:552-564) — this checks overall system health, still valuable
- Add: disk space check before conversion — estimate needed space as `(triangleCount * 12) + (triangleCount * 3 * 60)` bytes (triangle file + SQLite DB estimate), check `disk_free_space()` on the cache directory
- Deviation fix: `convertPartTo3MF()` at converter.php:572 uses `$result->fetchArray(PDO::FETCH_ASSOC)` — this is the SQLite3 API, not PDO. While editing this function to remove `ini_set`, also fix to use `$stmt->fetch(PDO::FETCH_ASSOC)` (PDO pattern). Same fix at converter.php:687 in `estimatePartConversion()`

**Definition of Done:**

- [ ] `BYTES_PER_TRIANGLE`, `getMaxTriangles()`, `getMemoryLimitBytes()` removed
- [ ] `ini_set('memory_limit', '8G')` removed from `convertPartTo3MF()`
- [ ] Triangle count ceiling checks removed from both parse methods
- [ ] Disk space pre-check added before conversion starts
- [ ] `getSystemMemory()` and system memory pre-check in `convertPartTo3MF()` preserved unchanged

**Verify:**

- `grep -n 'memory_limit\|BYTES_PER_TRIANGLE\|getMaxTriangles\|getMemoryLimitBytes' includes/converter.php` returns no results

---

### Task 5: Add unit tests

**Objective:** Add PHPUnit tests for the STLConverter class covering binary/ASCII parsing, 3MF generation, temp file cleanup, and estimate.

**Dependencies:** Tasks 1, 2, 3, 4

**Files:**

- Create: `tests/ConverterTest.php`
- Create: `tests/fixtures/cube.stl` (small binary STL test fixture — 12 triangles)
- Create: `tests/fixtures/cube-ascii.stl` (ASCII STL version of the cube)

**Key Decisions / Notes:**

- Follow project test infrastructure patterns (see `silo-test-infra` skill for PHPUnit setup)
- Test cases:
  1. `testParseBinarySTL` — parse cube.stl, verify vertex/triangle counts
  2. `testParseASCIISTL` — parse cube-ascii.stl, verify vertex/triangle counts
  3. `testConvertTo3MF` — convert cube.stl to 3MF, verify ZIP contains expected files, verify vertex/triangle XML counts
  4. `testEstimateBinarySTL` — estimate cube.stl, verify reasonable estimate
  5. `testEstimateASCIISTL` — estimate cube-ascii.stl, verify reasonable estimate
  6. `testTempCleanup` — after conversion, verify no temp files remain in cache dir
  7. `testIsBinarySTL` — verify binary/ASCII detection
  8. `testInvalidFile` — verify proper exception on missing/invalid files
- Generate test STL fixtures programmatically in a setup helper or commit small binary fixtures (a cube = 12 triangles = 684 bytes binary)
- Memory assertion: use `memory_get_peak_usage()` before/after to verify bounded usage (hard to test with small files, but document the assertion pattern)

**Definition of Done:**

- [ ] All 8 test cases pass
- [ ] Test fixtures committed (binary + ASCII cube STL)
- [ ] Tests verify 3MF ZIP structure and content
- [ ] Tests verify temp file cleanup
- [ ] Full test suite passes (0 failures)

**Verify:**

- `php vendor/bin/phpunit tests/ConverterTest.php`
