/**
 * UAS Part 107 Flight Operations Platform
 * Master Application Script (assets/app.js)
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

    const defaultLat = parseFloat(mapEl.dataset.lat || '37.115191');
    const defaultLng = parseFloat(mapEl.dataset.lng || '-88.811224');
    const isInteractive = mapEl.dataset.interactive === 'true';

    const map = L.map('flight-polygon-map', {
        zoomControl: true,
        attributionControl: false
    }).setView([defaultLat, defaultLng], 14);

    window.flightMap = map;

    // Cross-origin enabled so client-side canvas snapshot can read imagery tiles
    L.tileLayer('https://basemap.nationalmap.gov/arcgis/rest/services/USGSImageryOnly/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 18,
        crossOrigin: 'anonymous',
        attribution: 'USGS The National Map'
    }).addTo(map);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        opacity: 0.35,
        crossOrigin: 'anonymous'
    }).addTo(map);

    setTimeout(() => {
        map.invalidateSize();
    }, 150);

    let drawnPolygon = null;
    let markers = [];
    let polygonPoints = [];
    let isDrawing = false;

    const drawBtn = document.getElementById('btn-draw-polygon');
    const clearBtn = document.getElementById('btn-clear-polygon');
    const statusText = document.getElementById('polygon-draw-status');
    const acresDisplay = document.getElementById('display-acres');
    const centerDisplay = document.getElementById('display-center');
    const polygonInput = document.getElementById('polygon_geojson');
    const latInput = document.getElementById('latitude');
    const lngInput = document.getElementById('longitude');
    const acresInput = document.getElementById('operational_acres');

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

    detectAirspaceClass(defaultLat, defaultLng);

    if (!isInteractive) return;

    if (drawBtn) {
        drawBtn.addEventListener('click', () => {
            if (!isDrawing) {
                resetPolygon();
                isDrawing = true;
                drawBtn.textContent = 'Finish Polygon';
                drawBtn.className = 'btn btn-success btn-sm';
                if (statusText) statusText.textContent = 'Click map to place boundary points. Drag points to adjust before finishing.';
            } else {
                completePolygon();
            }
        });
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', resetPolygon);
    }

    map.on('click', (e) => {
        if (!isDrawing) return;

        const currentPointIndex = polygonPoints.length;
        polygonPoints.push(e.latlng);

        const markerIcon = L.divIcon({
            className: 'polygon-vertex-handle',
            html: '<div style="width: 14px; height: 14px; background: #00d2ff; border: 2px solid #ffffff; border-radius: 50%; box-shadow: 0 0 4px rgba(0,0,0,0.6); cursor: grab;"></div>',
            iconSize: [14, 14],
            iconAnchor: [7, 7]
        });

        const marker = L.marker(e.latlng, {
            draggable: true,
            icon: markerIcon,
            zIndexOffset: 1000
        }).addTo(map);

        marker.pointIndex = currentPointIndex;

        marker.on('drag', (dragEvent) => {
            if (!isDrawing) return;
            polygonPoints[marker.pointIndex] = dragEvent.target.getLatLng();
            redrawActiveShape();
        });

        marker.on('dragend', () => {
            updateTelemetry();
        });

        markers.push(marker);
        redrawActiveShape();
        updateTelemetry();
    });

    map.on('dblclick', (e) => {
        if (!isDrawing) return;
        L.DomEvent.stop(e);
        completePolygon();
    });

    function redrawActiveShape() {
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
    }

    function completePolygon() {
        if (polygonPoints.length < 3) {
            alert('A flight polygon requires at least 3 points to enclose airspace.');
            return;
        }

        isDrawing = false;
        if (drawBtn) {
            drawBtn.textContent = '⬟ Redraw Polygon';
            drawBtn.className = 'btn btn-aloft btn-sm';
        }
        if (statusText) statusText.textContent = 'Flight boundary established';

        markers.forEach(m => map.removeLayer(m));
        markers = [];

        if (drawnPolygon) map.removeLayer(drawnPolygon);

        drawnPolygon = L.polygon(polygonPoints, {
            color: '#00d2ff',
            fillColor: '#00d2ff',
            fillOpacity: 0.35,
            weight: 3
        }).addTo(map);

        updateTelemetry();
        window.generateMapSnapshot();
    }

    function resetPolygon() {
        isDrawing = false;
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
        if (statusText) statusText.textContent = 'Click "Draw Flight Polygon" to plot mission area';
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

        detectAirspaceClass(centerLat, centerLng);
    }

    // High-resolution client satellite snapshot generator (strips data: prefix to prevent WAF 406 errors)
    window.generateMapSnapshot = function() {
        const input = document.getElementById('map_image_base64');
        if (!input) return;

        const canvas = document.createElement('canvas');
        canvas.width = 584;
        canvas.height = 320;
        const ctx = canvas.getContext('2d');
        if (!ctx) return;

        ctx.fillStyle = '#0f172a';
        ctx.fillRect(0, 0, 584, 320);

        const mapContainer = document.getElementById('flight-polygon-map');
        if (!mapContainer) return;
        const mapRect = mapContainer.getBoundingClientRect();
        const tiles = mapContainer.querySelectorAll('.leaflet-tile-loaded');

        try {
            tiles.forEach(tile => {
                if (tile.naturalWidth && tile.complete) {
                    const rect = tile.getBoundingClientRect();
                    const x = (rect.left - mapRect.left) * (584 / mapRect.width);
                    const y = (rect.top - mapRect.top) * (320 / mapRect.height);
                    const w = rect.width * (584 / mapRect.width);
                    const h = rect.height * (320 / mapRect.height);
                    ctx.drawImage(tile, x, y, w, h);
                }
            });

            if (polygonPoints && polygonPoints.length >= 3) {
                ctx.beginPath();
                polygonPoints.forEach((p, idx) => {
                    const pt = map.latLngToContainerPoint(p);
                    const x = pt.x * (584 / mapRect.width);
                    const y = pt.y * (320 / mapRect.height);
                    if (idx === 0) ctx.moveTo(x, y);
                    else ctx.lineTo(x, y);
                });
                ctx.closePath();
                ctx.fillStyle = 'rgba(0, 210, 255, 0.25)';
                ctx.fill();
                ctx.strokeStyle = '#00d2ff';
                ctx.lineWidth = 3;
                ctx.stroke();
            }

            // HUD Overlays
            ctx.fillStyle = 'rgba(15, 23, 42, 0.85)';
            ctx.fillRect(10, 10, 310, 42);
            ctx.strokeStyle = '#334155';
            ctx.lineWidth = 1;
            ctx.strokeRect(10, 10, 310, 42);

            ctx.fillStyle = '#00d2ff';
            ctx.font = 'bold 12px sans-serif';
            ctx.fillText('FLIGHT AREA BOUNDARY MAP', 20, 26);

            const acres = document.getElementById('operational_acres') ? document.getElementById('operational_acres').value : '0';
            const alt = document.getElementById('max_altitude') ? document.getElementById('max_altitude').value : '200';
            ctx.fillStyle = '#ffffff';
            ctx.font = '10px sans-serif';
            ctx.fillText(`Footprint: ${acres} Ac | Ceiling: ${alt} ft AGL`, 20, 42);

            const dataUrl = canvas.toDataURL('image/jpeg', 0.80);
            // Strip data URI scheme so WAF / ModSecurity does not block the POST as XSS with HTTP 406
            input.value = dataUrl.replace(/^data:image\/[a-z]+;base64,/, '');
        } catch (e) {
            console.warn('Canvas snapshot skipped:', e);
        }
    };
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

    const faaEndpoint = `https://services6.arcgis.com/ssFJjBXIUyZDrSYZ/arcgis/rest/services/Class_Airspace/FeatureServer/0/query?geometry=${lng},${lat}&geometryType=esriGeometryPoint&inSR=4326&spatialRel=esriSpatialRelIntersects&outFields=*&returnGeometry=false&f=json`;

    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 3500);

    fetch(faaEndpoint, { signal: controller.signal })
        .then(response => response.json())
        .then(data => {
            clearTimeout(timeoutId);

            let bestClass = 'G';
            let bestName = 'Uncontrolled Surface Airspace';
            let highestPriority = 0;

            if (data && data.features && data.features.length > 0) {
                for (const feature of data.features) {
                    const attr = feature.attributes || {};
                    const rawClass = (attr.CLASS || attr.AIRSPACE_CLASS || '').trim().toUpperCase();
                    const localType = (attr.LOCAL_TYPE || attr.TYPE_CODE || '').toUpperCase();
                    const name = attr.NAME || attr.AIRPORT_NAME || 'Controlled Airspace';
                    const lowerVal = parseFloat(attr.LOWER_VAL !== undefined ? attr.LOWER_VAL : 0);
                    const lowerDesc = (attr.LOWER_DESC || attr.LOWER_CODE || '').toUpperCase();
                    const isSurface = (lowerVal === 0 || lowerDesc.includes('SFC') || lowerDesc.includes('SURFACE') || localType.includes('SURFACE'));

                    if ((rawClass === 'B' || localType.includes('CLASS B') || localType.includes('CLASS_B')) && (isSurface || lowerVal <= 400)) {
                        if (highestPriority < 100) {
                            highestPriority = 100;
                            bestClass = 'B';
                            bestName = `${name} (Class B)`;
                        }
                    } else if ((rawClass === 'C' || localType.includes('CLASS C') || localType.includes('CLASS_C')) && (isSurface || lowerVal <= 400)) {
                        if (highestPriority < 80) {
                            highestPriority = 80;
                            bestClass = 'C';
                            bestName = `${name} (Class C)`;
                        }
                    } else if (rawClass === 'D' || localType.includes('CLASS D') || localType.includes('CLASS_D')) {
                        if (highestPriority < 60) {
                            highestPriority = 60;
                            bestClass = 'D';
                            bestName = `${name} (Class D)`;
                        }
                    } else if (rawClass === 'E' || localType.includes('CLASS E') || localType.includes('CLASS_E')) {
                        const isSurfaceE = isSurface || localType.includes('E2') || localType.includes('SURFACE');
                        if (isSurfaceE && lowerVal === 0 && highestPriority < 40) {
                            highestPriority = 40;
                            bestClass = 'E';
                            bestName = `${name} (Class E Surface)`;
                        }
                    }
                }
            }

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
    const roleInput = document.getElementById('edit_role');

    document.querySelectorAll('.btn-edit-user').forEach(btn => {
        btn.addEventListener('click', () => {
            if (idInput) idInput.value = btn.dataset.id || '';
            if (nameInput) nameInput.value = btn.dataset.fullname || '';
            if (emailInput) emailInput.value = btn.dataset.email || '';
            if (phoneInput) phoneInput.value = btn.dataset.phone || '';
            if (roleInput) roleInput.value = btn.dataset.role || 'pilot';
            if (passInput) passInput.value = '';

            modal.style.display = 'flex';
        });
    });

    function closeModal() {
        modal.style.display = 'none';
    }

    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);

    modal.addEventListener('click', (e) => {
        if (e.target === modal) {
            closeModal();
        }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal.style.display === 'flex') {
            closeModal();
        }
    });
}
