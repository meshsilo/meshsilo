<!-- Three.js r183 via ES modules with import map -->
<script type="importmap">
{
    "imports": {
        "three": "https://cdn.jsdelivr.net/npm/three@0.183.2/build/three.module.min.js",
        "three/addons/": "https://cdn.jsdelivr.net/npm/three@0.183.2/examples/jsm/"
    }
}
</script>
<script type="module">
import * as THREE_MODULE from 'three';
import { STLLoader } from 'three/addons/loaders/STLLoader.js';
import { ThreeMFLoader } from 'three/addons/loaders/3MFLoader.js';
import { OBJLoader } from 'three/addons/loaders/OBJLoader.js';
import { PLYLoader } from 'three/addons/loaders/PLYLoader.js';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { ColladaLoader } from 'three/addons/loaders/ColladaLoader.js';
import { FBXLoader } from 'three/addons/loaders/FBXLoader.js';
import { TDSLoader } from 'three/addons/loaders/TDSLoader.js';
import { AMFLoader } from 'three/addons/loaders/AMFLoader.js';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';

// Spread into mutable object (ES module namespace is frozen)
const THREE = { ...THREE_MODULE };
THREE.STLLoader = STLLoader;
THREE.ThreeMFLoader = ThreeMFLoader;
THREE.OBJLoader = OBJLoader;
THREE.PLYLoader = PLYLoader;
THREE.GLTFLoader = GLTFLoader;
THREE.ColladaLoader = ColladaLoader;
THREE.FBXLoader = FBXLoader;
THREE.TDSLoader = TDSLoader;
THREE.AMFLoader = AMFLoader;
THREE.OrbitControls = OrbitControls;

// Expose to global scope
window.THREE = THREE;

// Load viewer scripts now that THREE is available
// Uses dynamic script injection to guarantee execution order
function loadScript(src) {
    return new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = src;
        s.onload = resolve;
        s.onerror = reject;
        document.head.appendChild(s);
    });
}

await loadScript('<?= basePath('js/viewer.js') ?>?v=<?= filemtime(__DIR__ . '/../../public/js/viewer.js') ?>');
await loadScript('<?= basePath('js/viewer-loaders.js') ?>?v=<?= filemtime(__DIR__ . '/../../public/js/viewer-loaders.js') ?>');

// Signal ready after viewer is fully loaded
window.THREE_READY = true;
window.dispatchEvent(new Event('three-ready'));
</script>
