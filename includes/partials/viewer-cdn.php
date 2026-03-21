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
import * as THREE from 'three';
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

// Expose to global scope for existing viewer.js and viewer-loaders.js
window.THREE = THREE;
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

// Signal that Three.js is ready
window.THREE_READY = true;
window.dispatchEvent(new Event('three-ready'));
</script>
