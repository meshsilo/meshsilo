# 3D Format Support in MeshSilo

MeshSilo supports a wide range of 3D model file formats with browser-based preview capabilities.

## Supported Formats

### Native Three.js Support (Instant Preview)
These formats load instantly using Three.js loaders:

| Format | Extension | Description | Preview |
|--------|-----------|-------------|---------|
| STL | .stl | Stereolithography | ✅ Three.js |
| 3MF | .3mf | 3D Manufacturing Format | ✅ Three.js |
| OBJ | .obj | Wavefront Object | ✅ Three.js |
| PLY | .ply | Polygon File Format | ✅ Three.js |
| GLTF/GLB | .gltf, .glb | GL Transmission Format | ✅ Three.js |
| Collada | .dae | Digital Asset Exchange | ✅ Three.js |
| FBX | .fbx | Autodesk Filmbox | ✅ Three.js |
| 3DS | .3ds | 3D Studio Max | ✅ Three.js |
| AMF | .amf | Additive Manufacturing | ✅ Three.js |
| G-Code | .gcode | CNC Instructions | ✅ Custom Viewer |

### CAD Format Support (On-Demand Loading)
CAD formats use OpenCascade.js which loads automatically when needed (~2MB download):

| Format | Extension | Description | Preview |
|--------|-----------|-------------|---------|
| STEP | .step, .stp | Standard for Product Exchange | ✅ OpenCascade.js |
| IGES | .iges, .igs | Initial Graphics Exchange | ✅ OpenCascade.js |

**Note:** OpenCascade.js loads only when viewing a CAD file for the first time in a session.

### No Preview Support
These formats are supported for upload but don't have browser preview:

| Format | Extension | Description | Reason |
|--------|-----------|-------------|--------|
| Blender | .blend | Blender Native Format | Proprietary binary format |
| DXF | .dxf | AutoCAD Drawing | 2D format, limited 3D support |
| OFF | .off | Object File Format | Uncommon, no standard loader |
| X3D | .x3d | Extensible 3D | Could be added if needed |

## Implementation Details

### Lazy Loading
- **Three.js loaders**: Pre-loaded in page header for instant availability
- **OpenCascade.js**: Loaded on-demand when viewing STEP/IGES files
  - Only downloads once per browser session
  - Approximately 2MB payload
  - Runs entirely in browser (WebAssembly)

### Preview Architecture
1. User clicks on model or navigates to model page
2. Viewer detects file type from `data-file-type` attribute
3. Appropriate loader is selected based on file extension
4. Model is loaded, centered, and rendered with default lighting
5. Interactive controls (pan, zoom, rotate) are enabled

### Adding New Format Support

To add support for a new format:

1. Add CDN script tag for Three.js loader in `includes/header.php`
2. Add loader method in `js/viewer.js` (e.g., `loadXYZ()`)
3. Add case to `loadModel()` switch statement
4. Update `actions/file-types.php` built-in types array
5. Add extension to `includes/config.php` ALLOWED_EXTENSIONS

## Performance Considerations

- **File Size Limits**: Models up to 100MB by default (configurable)
- **Browser Memory**: Large models may require significant RAM
- **Loading Time**: Varies by file size and format complexity
- **Mobile Support**: May struggle with very large/complex models

## Browser Compatibility

- **Modern Browsers**: Chrome, Firefox, Edge, Safari (latest versions)
- **WebGL Required**: All 3D previews require WebGL support
- **WebAssembly**: Required for CAD format support (STEP/IGES)

## Future Enhancements

Potential additions:
- DXF 3D viewer using three-dxf library
- X3D support using Three.js X3DLoader
- OFF format parser (simple vertex/face format)
- Server-side conversion for Blender files
- Point cloud rendering for scan data
- Texture/material preservation for complex formats
