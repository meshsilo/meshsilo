# MeshSilo API Quickstart

## Authentication

All API requests require an API key. Generate one in **Admin > API Keys**.

Pass the key via the `X-API-Key` header:

```bash
curl -H "X-API-Key: your_api_key_here" https://meshsilo.example.com/api/models
```

Or as a query parameter (less secure, not recommended for production):

```bash
curl https://meshsilo.example.com/api/models?api_key=your_api_key_here
```

### API Key Permissions

API keys have permission levels:
- **read** - List and view models, categories, tags
- **write** - Upload and edit models
- **delete** - Delete models
- **admin** - Full access

## Common Endpoints

### List Models

```bash
curl -H "X-API-Key: YOUR_KEY" \
  "https://meshsilo.example.com/api/models"
```

**Query parameters:**
| Parameter | Description | Example |
|-----------|-------------|---------|
| `q` | Search query | `?q=benchy` |
| `category_id` | Filter by category | `?category_id=5` |
| `tag_id` | Filter by tag | `?tag_id=3` |
| `file_type` | Filter by file type | `?file_type=stl` |
| `sort` | Sort order | `?sort=newest` (newest, oldest, name, downloads, updated) |
| `page` | Page number | `?page=2` |
| `limit` | Results per page (max 100) | `?limit=50` |

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Benchy",
      "filename": "3DBenchy.stl",
      "file_type": "stl",
      "file_size": 11968,
      "description": "3D printer benchmark",
      "download_count": 42,
      "created_at": "2025-01-15 10:30:00"
    }
  ],
  "pagination": {
    "page": 1,
    "limit": 20,
    "total": 150,
    "total_pages": 8
  }
}
```

### Get a Single Model

```bash
curl -H "X-API-Key: YOUR_KEY" \
  "https://meshsilo.example.com/api/models/123"
```

### Get Model Parts

```bash
curl -H "X-API-Key: YOUR_KEY" \
  "https://meshsilo.example.com/api/models/123/parts"
```

### Upload a Model

```bash
curl -X POST \
  -H "X-API-Key: YOUR_KEY" \
  -F "file=@/path/to/model.stl" \
  -F "name=My Model" \
  -F "description=A cool 3D model" \
  -F "tags=functional,gadget" \
  -F "category_ids=1,3" \
  "https://meshsilo.example.com/api/models"
```

**Form fields:**
| Field | Required | Description |
|-------|----------|-------------|
| `file` | Yes | The model file (multipart upload) |
| `name` | No | Display name (defaults to filename) |
| `description` | No | Model description |
| `tags` | No | Comma-separated tag names |
| `category_ids` | No | Comma-separated category IDs |
| `creator` | No | Creator/author name |
| `license` | No | License type |
| `source_url` | No | Source URL |
| `collection` | No | Collection name |
| `print_type` | No | Print type (fdm, sla, etc.) |

### Update a Model

```bash
curl -X PUT \
  -H "X-API-Key: YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"name": "Updated Name", "description": "New description"}' \
  "https://meshsilo.example.com/api/models/123"
```

**Updatable fields:** `name`, `description`, `creator`, `collection`, `source_url`, `license`, `print_type`, `notes`, `is_archived`, `is_printed`, `category_ids` (array), `tags` (array).

### Delete a Model

```bash
curl -X DELETE \
  -H "X-API-Key: YOUR_KEY" \
  "https://meshsilo.example.com/api/models/123"
```

### Download a Model File

```bash
curl -H "X-API-Key: YOUR_KEY" \
  -o model.stl \
  "https://meshsilo.example.com/api/models/123/download"
```

### List Categories

```bash
curl -H "X-API-Key: YOUR_KEY" \
  "https://meshsilo.example.com/api/categories"
```

### List Tags

```bash
curl -H "X-API-Key: YOUR_KEY" \
  "https://meshsilo.example.com/api/tags"
```

### List Collections

```bash
curl -H "X-API-Key: YOUR_KEY" \
  "https://meshsilo.example.com/api/collections"
```

### Get Statistics

```bash
curl -H "X-API-Key: YOUR_KEY" \
  "https://meshsilo.example.com/api/stats"
```

## Pagination

All list endpoints support pagination:

```bash
# Get page 3 with 50 results per page
curl -H "X-API-Key: YOUR_KEY" \
  "https://meshsilo.example.com/api/models?page=3&limit=50"
```

The response includes pagination metadata:

```json
{
  "data": [...],
  "pagination": {
    "page": 3,
    "limit": 50,
    "total": 250,
    "total_pages": 5
  }
}
```

The maximum `limit` is **100** results per page. Default is **20**.

## Rate Limiting

API requests are rate-limited per API key. Rate limit headers are included in every response:

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 58
X-RateLimit-Reset: 1700000000
```

If you exceed the limit, you will receive a `429 Too Many Requests` response:

```json
{
  "error": "Rate limit exceeded",
  "retry_after": 45,
  "tier": "standard"
}
```

## Error Handling

Errors return appropriate HTTP status codes with a JSON body:

```json
{
  "error": true,
  "message": "Model not found"
}
```

**Common status codes:**
| Code | Meaning |
|------|---------|
| 200 | Success |
| 201 | Created (successful upload) |
| 400 | Bad request (invalid input) |
| 401 | Unauthorized (invalid or missing API key) |
| 403 | Forbidden (insufficient permissions) |
| 404 | Not found |
| 405 | Method not allowed |
| 429 | Rate limit exceeded |
| 500 | Server error |

## GraphQL API

MeshSilo also provides a GraphQL endpoint at `/api/graphql`:

```bash
curl -X POST \
  -H "X-API-Key: YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"query": "{ models(limit: 5) { id name file_type tags { name } } }"}' \
  "https://meshsilo.example.com/api/graphql"
```

Available queries: `model`, `models`, `categories`, `tags`, `collections`, `me`, `stats`.

Available mutations: `toggleFavorite`, `addTag`, `trackDownload`.

## API Versioning

The API supports versioning via the `Accept` header:

```bash
curl -H "X-API-Key: YOUR_KEY" \
  -H "Accept: application/vnd.meshsilo.v1+json" \
  "https://meshsilo.example.com/api/models"
```

The current version is **v1**. When no version is specified, the latest version is used.
