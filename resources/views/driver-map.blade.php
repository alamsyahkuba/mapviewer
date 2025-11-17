@extends('index')
@section('title', 'Driver Map')

@push('scripts')
<script src="https://maps.googleapis.com/maps/api/js?key={{ env('GOOGLE_MAPS_API_KEY') }}&libraries=geometry&callback=initMap" async defer></script>
<script>
    let map, markers = {}, directionsService, directionsRenderer;
    let lastDriverPosition = null;
    let overlays = {}; // label overlays
    let polylines = [];

    function initMap() {
        const driverId = window.location.pathname.split('/').pop();

        map = new google.maps.Map(document.getElementById('map'), {
            center: { lat: -6.205, lng: 106.821 },
            zoom: 13,
            streetViewControl: false,
            mapTypeControl: true,
            fullscreenControl: true,
            styles: [
                { featureType: "poi", stylers: [{ visibility: "off" }] }
            ]
        });

        directionsService = new google.maps.DirectionsService();
        directionsRenderer = new google.maps.DirectionsRenderer({
            suppressMarkers: true,
        });

        const icons = {
            start_point: {
                url: "/images/gps_blue.png",
                scaledSize: new google.maps.Size(20, 30),
            },
            driver_position: {
                url: "/images/truck.png",
                scaledSize: new google.maps.Size(40, 40),
            },
            destination_active: {
                url: "/images/gps_green.png",
                scaledSize: new google.maps.Size(20, 30),
            },
            destination_inactive: {
                url: "/images/gps_gray.png",
                scaledSize: new google.maps.Size(20, 30),
            },
// 			driver_position: "http://maps.google.com/mapfiles/ms/icons/truck.png",
        };

        async function getETA(origin, dest) {
            return new Promise((resolve, reject) => {
                directionsService.route({
                    origin,
                    destination: dest,
                    travelMode: google.maps.TravelMode.DRIVING,
                    drivingOptions: {
                        departureTime: new Date(),
                        trafficModel: 'optimistic'
                    }
                }, (result, status) => {
                    if (status === google.maps.DirectionsStatus.OK) {
                        const leg = result.routes[0].legs[0];
                        const eta = leg.duration_in_traffic
                            ? leg.duration_in_traffic.value
                            : leg.duration.value;
                        resolve({ eta, result });
                    } else {
                        reject('Failed ETA');
                    }
                });
            });
        }

        async function getFastestDestination(originLatLng, destinationList) {
            let fastest = null;

            for (const dest of destinationList) {
                try {
                    const { eta, result } = await getETA(originLatLng, dest);
                    if (!fastest || eta < fastest.eta) {
                        fastest = { eta, result, dest };
                    }
                } catch (e) {
                    console.error("ETA error:", e);
                }
            }

            return fastest;
        }

        function setDestinationMarkerColors(activeDest) {
            for (const key in markers) {
                if (key.startsWith("dest")) {
                    if (
                        activeDest &&
                        markers[key].position.lat() === activeDest.lat &&
                        markers[key].position.lng() === activeDest.lng
                    ) {
                        markers[key].setIcon(icons.destination_active);
                        if (overlays[key]) overlays[key].updateLabel("Destination (Next)");
                    } else {
                        markers[key].setIcon(icons.destination_inactive);
                    }
                }
            }
        }

        async function fetchMarkers() {
            try {
                const res = await fetch(`/api/driver-pos/${driverId}`);
                if (!res.ok) throw new Error('Failed to fetch markers');
                const data = await res.json();

                const geocoder = new google.maps.Geocoder();
                const infoWindow = new google.maps.InfoWindow();
                const allPositions = [];

                // Clean old polyline
                polylines.forEach(line => line.setMap(null));
                polylines = [];

                // === START POINT ===
                if (data.start_point && data.start_point.lat && data.start_point.lng) {
                    const pos = {
                        lat: parseFloat(data.start_point.lat),
                        lng: parseFloat(data.start_point.lng)
                    };
                    allPositions.push(pos);
                    updateMarker('start_point', pos, icons.start_point, 'Start Point', 'blue', geocoder, infoWindow);
                }

                // === DRIVER POSITION ===
                if (data.driver_position && data.driver_position.lat && data.driver_position.lng) {
                    const pos = {
                        lat: parseFloat(data.driver_position.lat),
                        lng: parseFloat(data.driver_position.lng)
                    };
                    allPositions.push(pos);
                    updateMarker('driver_position', pos, icons.driver_position, 'Driver', 'red', geocoder, infoWindow);
                }

             	// === DESTINATIONS ===
                const destinationList = [];
                const destinationKeys = [];
                if (data.destination && typeof data.destination === 'object') {
                    Object.entries(data.destination).forEach(([key, dest]) => {
                        if (!dest.lat || !dest.lng) return;
                        if (dest.status === "done") {
                            if (markers[key]) {
                                markers[key].setMap(null);
                                delete markers[key];
                            }
                            if (overlays[key]) {
                                overlays[key].setMap(null);
                                delete overlays[key];
                            }
                            return;
                        }
                        
                        const pos = { lat: parseFloat(dest.lat), lng: parseFloat(dest.lng) };
                        allPositions.push(pos);
                        destinationList.push(pos);

                        const label = key.replace(/dest(\d+)/i, 'Destination $1');
                        
                        updateMarker(key, pos, icons.destination, label, 'green', geocoder, infoWindow);
                    });
                }

                // === Fit map bounds ===
                if (allPositions.length > 0 && !lastDriverPosition) {
                    const bounds = new google.maps.LatLngBounds();
                    allPositions.forEach(p => bounds.extend(p));
                    map.fitBounds(bounds);
                }

             	// === Determine fastest destination by ETA ===
                if (data.driver_position && destinationList.length > 0) {
                    const driverPos = new google.maps.LatLng(data.driver_position.lat, data.driver_position.lng);

                    const fastest = await getFastestDestination(driverPos, destinationList);

                    if (fastest && fastest.result) {
                    	setDestinationMarkerColors(fastest.dest);
                    	
                        const leg = fastest.result.routes[0].legs[0];

                        // update label driver
                        if (overlays['driver_position']) {
                            overlays['driver_position'].updateLabel(
                                `Driver\nDistance: ${leg.distance.text}\nETA: ${leg.duration_in_traffic ? leg.duration_in_traffic.text : leg.duration.text}`
                            );
                        }

                        // draw only fastest route
                        drawTrafficColoredRoute(fastest.result);

                        lastDriverPosition = driverPos;
                    }
                }
            } catch (err) {
                console.error('Error fetching markers:', err);
            }
        }

     	// Update marker colors & label for active
        function setDestinationMarkerColors(activeDest) {
            for (const key in markers) {
                if (key.startsWith("dest")) {
                    const isActive = activeDest && markers[key].position.lat() === activeDest.lat && markers[key].position.lng() === activeDest.lng;

                    markers[key].setIcon(isActive ? icons.destination_active : icons.destination_inactive);

                    if (overlays[key]) {
                        let baseLabel = overlays[key].currentText.split(' Active')[0];
                        overlays[key].updateLabel(isActive ? `${baseLabel} (Active)` : baseLabel);
                    }
                }
            }
        }
        
        // Helper for marker and label
        function updateMarker(type, pos, icon, labelText, color, geocoder, infoWindow) {
            if (markers[type]) {
                markers[type].setPosition(pos);
            } else {
                markers[type] = new google.maps.Marker({
                    position: pos,
                    map: map,
                    icon: icon
                });

                markers[type].addListener('click', () => {
                    geocoder.geocode({ location: pos }, (results, status) => {
                        if (status === 'OK' && results[0]) {
                            const address = results[0].formatted_address;
                            const placeName = results[0].address_components[0]?.long_name || "Lokasi";
                            const content = `
                                <div style="max-width: 250px; background-color: white; color: black;">
                                    <b>${placeName}</b><br>
                                    ${address}<br>
                                    <a href="https://www.google.com/maps/search/?api=1&query=${pos.lat},${pos.lng}"
                                        target="_blank" style="color: blue; text-decoration: underline;">
                                        View on Google Maps
                                    </a>
                                </div>`;
                            infoWindow.setContent(content);
                            infoWindow.open(map, markers[type]);
                        } else {
                            infoWindow.setContent("<b>Lokasi tidak ditemukan</b>");
                            infoWindow.open(map, markers[type]);
                        }
                    });
                });
            }

            // Label overlay
            if (overlays[type]) {
                overlays[type].update(pos);
                overlays[type].updateLabel(labelText);
            } else {
                overlays[type] = addMarkerLabel(map, pos, labelText, color);
            }
        }

        // Fetch data for the first time + refresh each 10 second
        fetchMarkers();
        setInterval(fetchMarkers, 10000);
    }

    // Polyline route with colors based on speed
    function drawTrafficColoredRoute(result) {
        const route = result.routes[0];
        route.legs.forEach(leg => {
            leg.steps.forEach(step => {
                const path = step.path;
                const duration = step.duration.value;
                const distance = step.distance.value;
                const speed = distance / duration;
                let color = "green";

                if (speed < 4) color = "red";
                else if (speed < 8) color = "orange";

                const line = new google.maps.Polyline({
                    path,
                    strokeColor: color,
                    strokeOpacity: 0.9,
                    strokeWeight: 5,
                    map
                });

                polylines.push(line);
            });
        });
    }

    // Custom overlay label
    function addMarkerLabel(map, position, text, color = "blue") {
        const div = document.createElement("div");
        div.innerText = text;
        div.style.position = "absolute";
        div.style.whiteSpace = "pre-line";
        div.style.fontWeight = "bold";
        div.style.fontSize = "14px";
        div.style.color = color;
        div.style.textShadow = `
            -2px -2px 0 #fff,
             2px -2px 0 #fff,
            -2px  2px 0 #fff,
             2px  2px 0 #fff
        `;
        div.style.transform = "translate(-110%, -100%)";
        div.style.backgroundColor = "rgba(255, 255, 255, 0.8)";
        div.style.padding = "2px 4px";
        div.style.borderRadius = "4px";

        const overlay = new google.maps.OverlayView();
        overlay.currentText = text;

        overlay.onAdd = function() {
            this.getPanes().overlayLayer.appendChild(div);
        };

        overlay.draw = function() {
            const projection = this.getProjection();
            const pos = projection.fromLatLngToDivPixel(new google.maps.LatLng(position.lat, position.lng));
            div.style.left = pos.x + "px";
            div.style.top = pos.y + "px";
        };

        overlay.onRemove = function() {
            div.parentNode.removeChild(div);
        };

        overlay.update = function(newPosition) {
            position = newPosition;
            this.draw();
        };

        overlay.updateLabel = function(newText) {
            div.innerText = newText;
            this.currentText = newText;
        };

        overlay.setMap(map);
        return overlay;
    }
</script>
@endpush

@section('content')
<div id="map" style="width:100%;height:100vh;"></div>
@endsection
