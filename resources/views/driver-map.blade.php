@extends('index')
@section('title', 'Driver Map')

@push('scripts')
<script src="https://maps.googleapis.com/maps/api/js?key={{ env('GOOGLE_MAPS_API_KEY') }}&libraries=geometry&callback=initMap" async defer></script>

<script>
/*
  Driver Map — production-clean single-file Blade script
  - Add Stop popup with search + suggestion list + pick-from-map
  - ES6-style classes: MarkerManager, RouteManager, StopManager, MapApp
  - Debounced geocode search
  - Waypoints wired into DirectionsService requests
  - window.extraStops kept for legacy/persistence
*/

(function () {
    /* ---------- Utilities ---------- */
    function debounce(fn, delay = 300) {
        let t;
        return (...args) => {
            clearTimeout(t);
            t = setTimeout(() => fn(...args), delay);
        };
    }

    /* ---------- MarkerManager ---------- */
    class MarkerManager {
        constructor(map) {
            this.map = map;
            this.markers = {};   // key -> google.maps.Marker
            this.overlays = {};  // key -> google.maps.OverlayView
        }

        upsertMarker(key, pos, icon, label, labelColor, geocoder, infoWindow) {
            if (!pos || typeof pos.lat !== 'number' || typeof pos.lng !== 'number') return;

            if (!this.markers[key]) {
                this.markers[key] = new google.maps.Marker({ position: pos, map: this.map, icon });
                this.markers[key].addListener('click', () => {
                    if (geocoder && infoWindow) {
                        geocoder.geocode({ location: pos }, (results, status) => {
                            if (status === 'OK' && results[0]) {
                                const r = results[0];
                                infoWindow.setContent(`
                                    <div style="max-width:250px;">
                                        <b>${r.address_components?.[0]?.long_name || ''}</b><br>
                                        ${r.formatted_address || ''}<br>
                                        <a href="https://www.google.com/maps/search/?api=1&query=${pos.lat},${pos.lng}"
                                           target="_blank" style="color:blue;">View on Google Maps</a>
                                    </div>
                                `);
                            } else {
                                infoWindow.setContent('<b>Lokasi tidak ditemukan</b>');
                            }
                            infoWindow.open(this.map, this.markers[key]);
                        });
                    }
                });
            } else {
                this.markers[key].setPosition(pos);
                if (icon) this.markers[key].setIcon(icon);
            }

            if (!this.overlays[key]) {
                this.overlays[key] = this._createLabelOverlay(pos, label, labelColor);
            } else {
                this.overlays[key].update(pos);
                this.overlays[key].updateLabel(label);
            }
        }

        remove(key) {
            if (this.markers[key]) {
                this.markers[key].setMap(null);
                delete this.markers[key];
            }
            if (this.overlays[key]) {
                this.overlays[key].setMap(null);
                delete this.overlays[key];
            }
        }

        _createLabelOverlay(position, text, color = 'blue') {
            let pos = { ...position };
            const div = document.createElement('div');
            div.className = 'mm-label';
            div.innerText = text;
            div.style.cssText = `
                position:absolute;font-weight:600;font-size:13px;
                background:rgba(255,255,255,0.92);padding:4px 6px;border-radius:4px;
                white-space:pre-line;text-shadow:0 1px 0 #fff;transform:translate(-110%,-100%);
                pointer-events:none;
            `;
            div.style.color = color;

            const overlay = new google.maps.OverlayView();
            overlay.div = div;
            overlay.currentText = text;

            overlay.onAdd = function() {
                this.getPanes().overlayLayer.appendChild(div);
            };

            overlay.draw = function() {
                const proj = this.getProjection();
                if (!proj) return;
                const p = proj.fromLatLngToDivPixel(new google.maps.LatLng(pos.lat, pos.lng));
                div.style.left = `${p.x}px`;
                div.style.top = `${p.y}px`;
            };

            overlay.update = function(newPos) {
                pos = { ...newPos };
                this.draw();
            };

            overlay.updateLabel = function(newText) {
                div.innerText = newText;
                this.currentText = newText;
            };

            overlay.onRemove = () => div.remove();
            overlay.setMap(this.map);
            return overlay;
        }
    }

    /* ---------- RouteManager ---------- */
    class RouteManager {
        constructor(map, directionsService) {
            this.map = map;
            this.directionsService = directionsService;
            this.polylines = [];
        }

        reset() {
            this.polylines.forEach(p => p.setMap(null));
            this.polylines = [];
        }

        drawTrafficColoredRoute(result) {
            this.reset();
            if (!result || !result.routes || !result.routes[0]) return;
            const legs = result.routes[0].legs || [];
            legs.forEach(leg => {
                leg.steps.forEach(step => {
                    const duration = (step.duration && step.duration.value) || 1;
                    const distance = (step.distance && step.distance.value) || 1;
                    const speed = distance / duration;
                    const color = speed < 4 ? 'red' : speed < 8 ? 'orange' : 'green';
                    const poly = new google.maps.Polyline({
                        path: step.path,
                        strokeColor: color,
                        strokeOpacity: 0.95,
                        strokeWeight: 5,
                        map: this.map
                    });
                    this.polylines.push(poly);
                });
            });
        }

        getETA(origin, dest, waypoints = []) {
            return new Promise((resolve, reject) => {
                const req = {
                    origin,
                    destination: dest,
                    travelMode: 'DRIVING',
                    drivingOptions: { departureTime: new Date(), trafficModel: 'optimistic' }
                };
                if (Array.isArray(waypoints) && waypoints.length) {
                    req.waypoints = waypoints;
                    req.optimizeWaypoints = true;
                }
                this.directionsService.route(req, (result, status) => {
                    if (status !== 'OK' || !result) return reject(new Error('Directions failed: ' + status));
                    const leg = result.routes[0].legs[0];
                    const eta = (leg.duration_in_traffic && leg.duration_in_traffic.value) || (leg.duration && leg.duration.value) || 0;
                    resolve({ eta, result, dest });
                });
            });
        }
    }

    /* ---------- MapApp Orchestrator ---------- */
    class MapApp {
        constructor() {
            this.map = null;
            this.markerManager = null;
            this.routeManager = null;
            this.directionsService = null;
            this.icons = null;
            this.lastDriverPosition = null;
            this.refreshIntervalId = null;
            this.driverId = null;
            this.currentStopsWaypoints = [];
        }

        init() {
            this.driverId = window.location.pathname.split('/').pop();
            this.map = new google.maps.Map(document.getElementById('map'), {
                center: { lat: -6.205, lng: 106.821 },
                zoom: 13,
                streetViewControl: false,
                fullscreenControl: true,
                mapTypeControl: true,
                styles: [{ featureType: 'poi', stylers: [{ visibility: 'off' }] }]
            });

            this.directionsService = new google.maps.DirectionsService();
            this.icons = this._loadIcons();
            this.markerManager = new MarkerManager(this.map);
            this.routeManager = new RouteManager(this.map, this.directionsService);

            this._processSummaryRoute();
            this._startRefresh();
        }

        _loadIcons() {
            const s20 = new google.maps.Size(20, 30);
            const s40 = new google.maps.Size(40, 40);
            return {
                start: { url: '/images/gps_blue.png', scaledSize: s20 },
                driver: { url: '/images/truck.png', scaledSize: s40 },
                destActive: { url: '/images/gps_green.png', scaledSize: s20 },
                destInactive: { url: '/images/gps_gray.png', scaledSize: s20 },
                stop: { url: '/images/gps_green.png', scaledSize: s20 }
            };
        }

        async _startRefresh() {
            await this._refreshMapOnce();
            this.refreshIntervalId = setInterval(() => this._refreshMapOnce(), 10000);
        }

        async _refreshMapOnce() {
            const data = await this._fetchDriverData(this.driverId);
            if (!data) return;

            this.routeManager.reset();

            const positions = [];
            this._processStartAndDriver(data, positions);
            this._processStops(data, positions);
            const destList = this._processDestinations(data, positions);

            this._fitBoundsIfNeeded(positions);

            if (data.driver_position && destList.length) {
                await this._processFastestRoute(data.driver_position, destList);
            }
        }

        async _fetchDriverData(driverId) {
            try {
                const res = await fetch(`/api/driver-pos/${driverId}`);
                if (!res.ok) throw new Error('Fetch failed');
                return await res.json();
            } catch (err) {
                console.error('Fetch error', err);
                return null;
            }
        }

        _processStartAndDriver(data, positions) {
            const geocoder = new google.maps.Geocoder();
            const infoWindow = new google.maps.InfoWindow();

            const add = (key, item, icon, label, color) => {
                if (!item?.lat || !item?.lng) return;
                const pos = this._parseLatLng(item);
                positions.push(pos);
                this.markerManager.upsertMarker(key, pos, icon, label, color, geocoder, infoWindow);
            };

            add('start', data.start_point, this.icons.start, 'Start Point', 'blue');
            add('driver', data.driver_position, this.icons.driver, 'Driver', 'red');
        }

        _processDestinations(data, positions) {
            const geocoder = new google.maps.Geocoder();
            const infoWindow = new google.maps.InfoWindow();
            const list = [];

            if (!data.destination) return list;

            Object.entries(data.destination).forEach(([key, dest]) => {
                if (!dest.lat || !dest.lng) return;
                if (dest.status === 'done') {
                    this.markerManager.remove(key);
                    return;
                }
                const pos = this._parseLatLng(dest);
                list.push(pos);
                positions.push(pos);
                const label = key.replace(/dest(\d+)/i, 'Destination $1');
                this.markerManager.upsertMarker(key, pos, this.icons.destInactive, label, 'gray', geocoder, infoWindow);
            });

            return list;
        }

        _processStops(data, positions) {
            this.currentStopsWaypoints = [];

            const stops = data.stop_point;
            if (!stops || typeof stops !== 'object' || Object.keys(stops).length === 0) {
                // delete old stop marker
                for (const key in this.markerManager.markers) {
                    if (key.startsWith('stop')) this.markerManager.remove(key);
                }
                return;
            }

            const geocoder = new google.maps.Geocoder();
            const infoWindow = new google.maps.InfoWindow();

            Object.entries(stops).forEach(([key, s]) => {
                if (!s.lat || !s.lng) return;

                const pos = { lat: parseFloat(s.lat), lng: parseFloat(s.lng) };
                positions.push(pos);

                this.markerManager.upsertMarker(
                    key,
                    pos,
                    this.icons.stop,
                    `Stop Point ${key.replace('stop', '')}`,
                    'black',
                    geocoder,
                    infoWindow
                );

                this.currentStopsWaypoints.push({ location: pos });
            });
        }

        async _processFastestRoute(driverPosRaw, destList) {
            const driverPos = new google.maps.LatLng(driverPosRaw.lat, driverPosRaw.lng);
            let best = null;

            const stopsWaypoints = this.currentStopsWaypoints || [];
            
            for (const dest of destList) {
                try {
                    const r = await this.routeManager.getETA(driverPos, dest, stopsWaypoints);
                    if (!best || r.eta < best.eta) best = r;
                } catch (err) {
                    // ignore single failures
                }
            }

            if (!best) return;

            this._highlightActiveDestination(best.dest);

            const leg = best.result.routes[0].legs[0];
            if (this.markerManager.overlays['driver']) {
                const etaText = leg.duration_in_traffic?.text || leg.duration.text;
                this.markerManager.overlays['driver'].updateLabel(`Driver\nDistance: ${leg.distance.text}\nETA: ${etaText}`);
            }

            this.routeManager.drawTrafficColoredRoute(best.result);
            this.lastDriverPosition = driverPos;
        }

        _highlightActiveDestination(active) {
            const tol = 1e-6;
            for (const key in this.markerManager.markers) {
                if (!key.startsWith('dest')) continue;
                const marker = this.markerManager.markers[key];
                const overlay = this.markerManager.overlays[key];
                if (!marker || !overlay) continue;

                const isActive =
                    Math.abs(marker.getPosition().lat() - active.lat) < tol &&
                    Math.abs(marker.getPosition().lng() - active.lng) < tol;

                marker.setIcon(isActive ? this.icons.destActive : this.icons.destInactive);
                overlay.updateLabel(isActive ? `${overlay.currentText} (Active)` : overlay.currentText.replace(' (Active)', ''));
                overlay.div.style.color = isActive ? 'green' : 'gray';
            }
        }

        _fitBoundsIfNeeded(list) {
            if (!list.length || this.lastDriverPosition) return;
            const bounds = new google.maps.LatLngBounds();
            list.forEach(p => bounds.extend(p));
            this.map.fitBounds(bounds);
        }

        _parseLatLng(o) {
            return { lat: parseFloat(o.lat), lng: parseFloat(o.lng) };
        }

        async _processSummaryRoute() {
        	const data = await this._fetchDriverData(this.driverId);
         	getRouteData(data).then(res => console.log(JSON.stringify(res, null, 2)));
        }
    }

    /* ---------- Summarizer Utility ---------- */
    function getRouteData(data) {
        return new Promise((resolve, reject) => {
            const directionsService = new google.maps.DirectionsService();
    
            const start = data.start_point;
    
            const destinationKeys = Object.keys(data.destination);
    
            const destinationsArray = destinationKeys.map(k => {
                const d = data.destination[k];
                return { key: k, lat: d.lat, lng: d.lng };
            });
    
            if (destinationsArray.length === 0) {
                reject("destination kosong");
                return;
            }
    
            const finalDest = {
                lat: destinationsArray[destinationsArray.length - 1].lat,
                lng: destinationsArray[destinationsArray.length - 1].lng
            };
    
            const waypoints = destinationsArray.slice(0, -1).map(p => ({
                location: { lat: p.lat, lng: p.lng }
            }));
    
            directionsService.route(
                {
                    origin: start,
                    destination: finalDest,
                    waypoints: waypoints,
                    travelMode: google.maps.TravelMode.DRIVING,
                    optimizeWaypoints: false
                },
                (result, status) => {
                    if (status !== google.maps.DirectionsStatus.OK) {
                        reject("Gagal ambil rute: " + status);
                        return;
                    }
    
                    const legs = result.routes[0].legs;
    
                    const response = {
                        total_distance_m: legs.reduce((s, l) => s + l.distance.value, 0),
                        total_distance_text: formatTotalText(
    						legs.reduce((s, b) => s + b.distance.value, 0),
    						"km"
    					),    
                        total_eta_seconds: legs.reduce((s, l) => s + l.duration.value, 0),
                        total_eta_text: formatTotalText(
    						legs.reduce((s, b) => s + b.duration.value, 0),
    						"mins"
    					),
                        segments: legs.map((leg, idx) => {
                            const from = idx === 0 ? "Start" : destinationKeys[idx - 1];
                            const to = destinationKeys[idx];
    
                            return {
                                from,
                                to,
                                distance_m: leg.distance.value,
                                distance_text: leg.distance.text,
                                eta_seconds: leg.duration.value,
                                eta_text: leg.duration.text
                            };
                        })
                    };
    
                    resolve(response);
                }
            );
        });
    }

 	// Helper formatting
    function formatTotalText(value, unit) {
    	if (unit === "km") {
    		return (value / 1000).toFixed(1) + " km";
    	}
    	if (unit === "mins") {
    		return Math.round(value / 60) + " mins";
    	}
    	return value + "";
    }
    
    /* ---------- bootstrap ---------- */
    window.extraStops = window.extraStops || []; // keep legacy global

    window.initMap = function initMap() {
        const app = new MapApp();
        app.init();
        window._DriverMapApp = app; // expose for debugging
    };
})();
</script>

<style>
/* small CSS improvements */
.mm-label { pointer-events: none; }
#popupStop { display:none; }
#searchResults .search-row:hover { background:#f6f6f6; }
#btnAddStop { cursor:pointer; }

/* suggestion box */
#stopSuggestions { display:none; border:1px solid #e6e6e6; border-radius:6px; background:#fff; box-shadow:0 6px 18px rgba(0,0,0,0.06); max-height:220px; overflow-y:auto; margin-top:8px; }

/* responsive tweak for map on small screens */
@media (max-width:600px) {
    #popupStop { width:90%; }
}
</style>
@endpush

@section('content')
<div id="map" style="width:100%;height:100vh;"></div>
@endsection
