/**
 * UAS Part 107 Flight Operations Platform
 * Master Application Script (assets/app.js)
 * 
 * Includes:
 * 1. Leaflet Interactive Satellite Map & Aloft-Style Polygon Selector
 * 2. Real-Time FAA AIS Class Airspace GIS Classifier & Priority Scorer
 * 3. Dynamic Altitude & LAANC Advisory Evaluator
 * 4. User Edit Pop-Up Modal Dialog Controller
 */

document.addEventListener('DOMContentLoaded', () => {
    initFlightPlannerMap();
    initAltitudeEvaluator();
    initUserEditModal();
});

/* ==========================================================================
   1. LEAFLET MAP & TACTICAL POLYGON DRAWING ENGINE
   ========================================================================== */
function initFlightPlannerMap() {
    const mapEl = document.getElementById('flight-polygon-map');
    if (!mapEl) return;

    if (typeof L === 'undefined') {
        mapEl.innerHTML = '<div style="color: #ef4444; padding: 2rem; text-align: center; font-weight: 600;">Leaflet map engine failed to load. Check internet connection.</div>';
        console.error('Leaflet library (L) is not defined.');
        return;
    }

    // Default Coordinates (Barkley Regional / Paducah Area)
    const defaultLat = parseFloat(mapEl.dataset.lat || '37.115191');
    const defaultLng = parseFloat(mapEl.dataset.lng || '-88.811224');
    const isInteractive = mapEl.dataset.interactive === 'true';

    // Initialize Leaflet Map
    const map = L.map('flight-polygon-map', {
        zoomControl: true,
        attributionControl: false
    }).setView([defaultLat, defaultLng], 14);

    // Hybrid Satellite Imagery Layer (ESRI World Imagery)
    L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 19
    }).addTo(map);

    // Streets & Boundary Vector Overlay
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        opacity: 0.35
    }).addTo(map);

    // Invalidate size to ensure container bounds render cleanly
    setTimeout(() => {
        map.invalidateSize();
    }, 150);

    let drawnPolygon = null;
    let markers = [];
    let polygonPoints = [];
    let isDrawing = false;

    // UI & Form Elements
    const drawBtn = document.getElementById('btn-draw-polygon');
    const clearBtn = document.getElementById('btn-clear-polygon');
    const statusText = document.getElementById('polygon-draw-status');
    const acresDisplay = document.getElementById('display-acres');
    const centerDisplay = document.getElementById('display-center');
    const polygonInput = document.getElementById('polygon_geojson');
    const latInput = document.getElementById('latitude');
    const lngInput = document.getElementById('longitude');
    const acresInput = document.getElementById('operational_acres');

    // Preload polygon if present (flight_details view)
    if (polygonInput && polygonInput.value) {
        try {
            const existingGeo = JSON.parse(polygonInput.value);
            if (existingGeo && existingGeo.coordinates && existingGeo.coordinates[0]) {
                const latLngs = existingGeo.coordinates[0].map(c => [c[1], c[0]]);
                drawnPolygon = L.polygon(latLngs, {
                    color: '#00d2ff',
                    fillColor: '#00d2ff',
                    fillOpacity: 0.25,
                    weight: 3
                }).addTo(map);
                map.fitBounds(drawnPolygon.getBounds(), { padding: [40, 40] });
            }
        } catch (e) {
            console.error('Failed to parse existing polygon GeoJSON', e);
        }
    }

    // Run initial airspace query for default centroid
    detectAirspaceClass(defaultLat, defaultLng);

    if (!isInteractive) return; // Read-only on detail screens

    // Polygon Draw Button Toggle
    if (drawBtn) {
        drawBtn.addEventListener('click', () => {
            isDrawing = !isDrawing;
            if (isDrawing) {
                resetPolygon();
                drawBtn.textContent = 'Finish Polygon (Double-Click Map)';
                drawBtn.className = 'btn btn-success btn-sm';
                if (statusText) statusText.textContent = 'Click to drop boundary points. Double-click map to close polygon.';
            } else {
                completePolygon();
            }
        });
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', resetPolygon);
    }

    // Click Map to Add Boundary Vertices
    map.on('click', (e) => {
        if (!isDrawing) return;

        polygonPoints.push(e.latlng);

        const marker = L.circleMarker(e.latlng, {
            radius: 5,
            color: '#ffffff',
            fillColor: '#00d2ff',
            fillOpacity: 1,
            weight: 2
        }).addTo(map);
        markers.push(marker);

        if (drawnPolygon) map.removeLayer(drawnPolygon);

        if (polygonPoints.length > 2) {
            drawnPolygon = L.polygon(polygonPoints, {
                color: '#00d2ff',
                fillColor: '#00d2ff',
                fillOpacity: 0.25,
                weight: 3
            }).addTo(map);
        } else if (polygonPoints.length === 2) {
            drawnPolygon = L.polyline(polygonPoints, {
                color: '#00d2ff',
                weight: 3,
                dashArray: '5, 5'
            }).addTo(map);
        }

        updateTelemetry();
    });

    // Double-Click Map to Finalize Boundary
    map.on('dblclick', (e) => {
        if (!isDrawing) return;
        L.DomEvent.stop(e);
        completePolygon();
    });

    function completePolygon() {
        if (polygonPoints.length < 3) {
            alert('A flight polygon requires at least 3 points to enclose airspace.');
            return;
        }

        isDrawing = false;
        if (drawBtn) {
            drawBtn.textContent = 'Redraw Polygon';
            drawBtn.className = 'btn btn-aloft btn-sm';
        }
        if (statusText) statusText.textContent = 'Flight boundary enclosed.';

        if (drawnPolygon) map.removeLayer(drawnPolygon);

        drawnPolygon = L.polygon(polygonPoints, {
            color: '#00d2ff',
            fillColor: '#00d2ff',
            fillOpacity: 0.35,
            weight: 3
        }).addTo(map);

        updateTelemetry();
    }

    function resetPolygon() {
        polygonPoints = [];
        markers.forEach(m => map.removeLayer(m));
        markers = [];
        if (drawnPolygon) {
            map.removeLayer(drawnPolygon);
            drawnPolygon = null;
        }
        if (polygonInput) polygonInput.value = '';
        if (acresDisplay) acresDisplay.textContent = '0.00';
        if (centerDisplay) centerDisplay.textContent = `${defaultLat}, ${defaultLng}`;
        if (acresInput) acresInput.value = '0';
        if (statusText) statusText.textContent = 'Click "Draw Flight Polygon" to plot mission area.';
        if (drawBtn) {
            drawBtn.textContent = '⬟ Draw Flight Polygon';
            drawBtn.className = 'btn btn-aloft btn-sm';
        }
        detectAirspaceClass(defaultLat, defaultLng);
    }

    function updateTelemetry() {
        if (polygonPoints.length < 3) return;

        let latSum = 0;
        let lngSum = 0;
        polygonPoints.forEach(p => {
            latSum += p.lat;
            lngSum += p.lng;
        });
        const centerLat = parseFloat((latSum / polygonPoints.length).toFixed(6));
        const centerLng = parseFloat((lngSum / polygonPoints.length).toFixed(6));

        // Shoelace Formula for Area Calculation (Acres)
        let areaSqMeters = 0;
        const total = polygonPoints.length;
        for (let i = 0; i < total; i++) {
            const j = (i + 1) % total;
            const xi = polygonPoints[i].lng * (Math.PI / 180) * 6378137 * Math.cos(polygonPoints[i].lat * (Math.PI / 180));
            const yi = polygonPoints[i].lat * (Math.PI / 180) * 6378137;
            const xj = polygonPoints[j].lng * (Math.PI / 180) * 6378137 * Math.cos(polygonPoints[j].lat * (Math.PI / 180));
            const yj = polygonPoints[j].lat * (Math.PI / 180) * 6378137;
            areaSqMeters += (xi * yj) - (xj * yi);
        }
        areaSqMeters = Math.abs(areaSqMeters / 2.0);
        const calculatedAcres = (areaSqMeters * 0.000247105).toFixed(2);

        // GeoJSON Closed Ring
        const coords = polygonPoints.map(p => [parseFloat(p.lng.toFixed(6)), parseFloat(p.lat.toFixed(6))]);
        coords.push(coords[0]);

        const geoJson = {
            type: "Polygon",
            coordinates: [coords]
        };

        if (polygonInput) polygonInput.value = JSON.stringify(geoJson);
        if (latInput) latInput.value = centerLat;
        if (lngInput) lngInput.value = centerLng;
        if (acresInput) acresInput.value = calculatedAcres;
        if (acresDisplay) acresDisplay.textContent = calculatedAcres;
        if (centerDisplay) centerDisplay.textContent = `${centerLat}, ${centerLng}`;

        // Auto-query FAA airspace for the new centroid
        detectAirspaceClass(centerLat, centerLng);
    }
}

/* ==========================================================================
   2. FAA AIS CLASS AIRSPACE CLASSIFIER & PRIORITY ENGINE
   ========================================================================== */
function detectAirspaceClass(lat, lng) {
    const badgeEl = document.getElementById('display-airspace-badge');
    const descEl = document.getElementById('display-airspace-desc');
    const hiddenInput = document.getElementById('airspace_class');

    if (!hiddenInput) return;

    if (badgeEl) {
        badgeEl.textContent = '🛰️ Querying FAA GIS...';
        badgeEl.className = 'badge badge-info';
    }
    if (descEl) {
        descEl.textContent = `Analyzing FAA airspace at [${lat}, ${lng}]...`;
    }

    // Official FAA AIS Class Airspace ArcGIS Feature Server
    const faaEndpoint = `https://services6.arcgis.com/ssFJjBXIUyZDrSYZ/arcgis/rest/services/Class_Airspace/FeatureServer/0/query?geometry=${lng},${lat}&geometryType=esriGeometryPoint&inSR=4326&spatialRel=esriSpatialRelIntersects&outFields=*&returnGeometry=false&f=json`;

    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 3500);

    fetch(faaEndpoint, { signal: controller.signal })
        .then(response => response.json())
        .then(data => {
            clearTimeout(timeoutId);

            let bestClass = 'G';
            let bestName = 'Uncontrolled Surface Airspace';
            let highestPriority = 0; // 100: B, 80: C, 60: D, 40: Surface E, 0: Transition E / G

            if (data && data.features && data.features.length > 0) {
                for (const feature of data.features) {
                    const attr = feature.attributes || {};
                    const rawClass = (attr.CLASS || attr.AIRSPACE_CLASS || '').trim().toUpperCase();
                    const localType = (attr.LOCAL_TYPE || attr.TYPE_CODE || '').toUpperCase();
                    const name = attr.NAME || attr.AIRPORT_NAME || 'Controlled Airspace';
                    const lowerVal = parseFloat(attr.LOWER_VAL !== undefined ? attr.LOWER_VAL : 0);
                    const lowerDesc = (attr.LOWER_DESC || attr.LOWER_CODE || '').toUpperCase();

                    const isSurface = (lowerVal === 0 || lowerDesc.includes('SFC') || lowerDesc.includes('SURFACE') || localType.includes('SURFACE'));

                    // Priority 1: Class B
                    if ((rawClass === 'B' || localType.includes('CLASS B') || localType.includes('CLASS_B')) && (isSurface || lowerVal <= 400)) {
                        if (highestPriority < 100) {
                            highestPriority = 100;
                            bestClass = 'B';
                            bestName = `${name} (Class B)`;
                        }
                    }
                    // Priority 2: Class C
                    else if ((rawClass === 'C' || localType.includes('CLASS C') || localType.includes('CLASS_C')) && (isSurface || lowerVal <= 400)) {
                        if (highestPriority < 80) {
                            highestPriority = 80;
                            bestClass = 'C';
                            bestName = `${name} (Class C)`;
                        }
                    }
                    // Priority 3: Class D (Barkley Regional / Paducah)
                    else if (rawClass === 'D' || localType.includes('CLASS D') || localType.includes('CLASS_D')) {
                        if (highestPriority < 60) {
                            highestPriority = 60;
                            bestClass = 'D';
                            bestName = `${name} (Class D)`;
                        }
                    }
                    // Priority 4: Class E Surface Area (Class E2)
                    else if (rawClass === 'E' || localType.includes('CLASS E') || localType.includes('CLASS_E')) {
                        const isSurfaceE = isSurface || localType.includes('E2') || localType.includes('SURFACE');
                        if (isSurfaceE && lowerVal === 0 && highestPriority < 40) {
                            highestPriority = 40;
                            bestClass = 'E';
                            bestName = `${name} (Class E Surface)`;
                        }
                    }
                }
            }

            // Barkley Regional Airport (KPAH) 4.1 NM Class D lateral boundary check
            const dLat = (lat - 37.0608) * 111.0;
            const dLng = (lng - (-88.7738)) * 88.6;
            const distFromPahKm = Math.sqrt(dLat * dLat + dLng * dLng);
            if (distFromPahKm <= 7.8 && highestPriority < 60) {
                bestClass = 'D';
                bestName = 'Barkley Regional (Class D)';
            }

            applyAirspaceClassification(bestClass, bestName, lat, lng);
        })
        .catch(() => {
            clearTimeout(timeoutId);
            const dLat = (lat - 37.0608) * 111.0;
            const dLng = (lng - (-88.7738)) * 88.6;
            const distFromPahKm = Math.sqrt(dLat * dLat + dLng * dLng);

            if (distFromPahKm <= 7.8) {
                applyAirspaceClassification('D', 'Barkley Regional (Class D)', lat, lng);
            } else {
                applyAirspaceClassification('G', 'Uncontrolled Surface Airspace', lat, lng);
            }
        });
}

function applyAirspaceClassification(airspaceClass, airspaceName, lat, lng) {
    const badgeEl = document.getElementById('display-airspace-badge');
    const descEl = document.getElementById('display-airspace-desc');
    const mapAirspaceEl = document.getElementById('display-map-airspace');
    const hiddenInput = document.getElementById('airspace_class');
    const laancBadge = document.getElementById('laanc-status-indicator');
    const altInput = document.getElementById('max_altitude');

    if (hiddenInput) hiddenInput.value = airspaceClass;

    if (badgeEl) {
        if (airspaceClass === 'G') {
            badgeEl.textContent = 'Class G (Uncontrolled)';
            badgeEl.className = 'badge badge-success';
        } else {
            badgeEl.textContent = `Class ${airspaceClass} (${airspaceName})`;
            badgeEl.className = 'badge badge-warning';
        }
    }

    if (descEl) {
        descEl.textContent = `Auto-detected from polygon centroid [${lat}, ${lng}]`;
    }

    if (mapAirspaceEl) {
        mapAirspaceEl.textContent = `Class ${airspaceClass}`;
        mapAirspaceEl.style.color = airspaceClass === 'G' ? '#22c55e' : '#f59e0b';
    }

    // Refresh LAANC guidance based on detected class and planned altitude
    const alt = parseInt(altInput ? altInput.value : '200', 10);
    if (laancBadge) {
        if (airspaceClass === 'G') {
            if (alt > 400) {
                laancBadge.textContent = 'EXCEEDS PART 107 LIMIT: Operations above 400ft AGL require an FAA waiver.';
                laancBadge.className = 'alert alert-danger';
            } else {
                laancBadge.textContent = 'Class G Airspace (Uncontrolled). Standard Part 107 rules apply. LAANC authorization is NOT required.';
                laancBadge.className = 'alert alert-success';
            }
        } else {
            if (alt > 400) {
                laancBadge.textContent = `Controlled Airspace (Class ${airspaceClass}): Altitudes above 400ft require an FAA Part 107 waiver.`;
                laancBadge.className = 'alert alert-danger';
            } else {
                laancBadge.textContent = `Class ${airspaceClass} Controlled Airspace: FAA LAANC authorization required via Aloft Air Control.`;
                laancBadge.className = 'alert alert-warning';
            }
        }
    }
}

/* ==========================================================================
   3. ALTITUDE LISTENER & AIRSPACE ADVISORY
   ========================================================================== */
function initAltitudeEvaluator() {
    const altInput = document.getElementById('max_altitude');
    if (altInput) {
        altInput.addEventListener('input', () => {
            const hiddenInput = document.getElementById('airspace_class');
            const latInput = document.getElementById('latitude');
            const lngInput = document.getElementById('longitude');
            const lat = latInput ? latInput.value : '37.115191';
            const lng = lngInput ? lngInput.value : '-88.811224';
            applyAirspaceClassification(hiddenInput ? hiddenInput.value : 'D', '', lat, lng);
        });
    }
}

/* ==========================================================================
   4. USER EDIT POP-UP MODAL DIALOG CONTROLLER
   ========================================================================== */
function initUserEditModal() {
    const modal = document.getElementById('edit-user-modal');
    if (!modal) return;

    const closeBtn = document.getElementById('modal-close-x');
    const cancelBtn = document.getElementById('modal-cancel-btn');

    const idInput = document.getElementById('edit_user_id');
    const nameInput = document.getElementById('edit_full_name');
    const passInput = document.getElementById('edit_password');
    const emailInput = document.getElementById('edit_email');
    const phoneInput = document.getElementById('edit_phone');
    const certInput = document.getElementById('edit_part107_cert_num');
    const roleInput = document.getElementById('edit_role');

    // Attach click listeners to all Edit buttons safely
    document.querySelectorAll('.btn-edit-user').forEach(btn => {
        btn.addEventListener('click', () => {
            if (idInput) idInput.value = btn.dataset.id || '';
            if (nameInput) nameInput.value = btn.dataset.fullname || '';
            if (emailInput) emailInput.value = btn.dataset.email || '';
            if (phoneInput) phoneInput.value = btn.dataset.phone || '';
            if (certInput) certInput.value = btn.dataset.cert || '';
            if (roleInput) roleInput.value = btn.dataset.role || 'pilot';
            if (passInput) passInput.value = ''; // Always clear password on open

            modal.style.display = 'flex';
        });
    });

    function closeModal() {
        modal.style.display = 'none';
    }

    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);

    // Close when clicking directly on the backdrop
    modal.addEventListener('click', (e) => {
        if (e.target === modal) {
            closeModal();
        }
    });

    // Close on Escape key press
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal.style.display === 'flex') {
            closeModal();
        }
    });
}
