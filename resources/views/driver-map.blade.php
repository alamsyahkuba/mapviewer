@extends('index')
@section('title', 'Driver Map')

@push('scripts')
<script src="https://maps.googleapis.com/maps/api/js?key={{ env('GOOGLE_MAPS_API_KEY') }}&libraries=geometry&callback=initMap" async defer></script>

<script>
let map, markers = {}, overlays = {}, polylines = [];
let directionsService, lastDriverPosition = null;
let icons = {};

function initMap() {
    const driverId = window.location.pathname.split('/').pop();

    map = new google.maps.Map(document.getElementById('map'), {
        center: { lat: -6.205, lng: 106.821 },
        zoom: 13,
        streetViewControl: false,
        fullscreenControl: true,
        mapTypeControl: true,
        styles: [{ featureType: "poi", stylers: [{ visibility: "off" }] }]
    });

    directionsService = new google.maps.DirectionsService();
    icons = loadIcons();

    const geocoder = new google.maps.Geocoder();
    const infoWindow = new google.maps.InfoWindow();

    async function refreshMap() {
        const data = await fetchDriverData(driverId);
        if (!data) return;

        resetPolylines();

        const positions = [];
        processStartAndDriver(data, geocoder, infoWindow, positions);

        const destinationList = processDestinations(data, geocoder, infoWindow, positions);

        fitMapBounds(positions);

        if (data.driver_position && destinationList.length > 0) {
            await processFastestRoute(data.driver_position, destinationList);
        }
    }

    refreshMap();
    setInterval(refreshMap, 10000);
}

/* FETCH DATA */
async function fetchDriverData(driverId) {
    try {
        const res = await fetch(`/api/driver-pos/${driverId}`);
        if (!res.ok) throw new Error("Fetch failed");
        return res.json();
    } catch (e) {
        console.error("Fetch error:", e);
        return null;
    }
}

/* ICONS */
function loadIcons() {
    const size20 = new google.maps.Size(20, 30);
    const size40 = new google.maps.Size(40, 40);

    return {
        start: { url: "/images/gps_blue.png", scaledSize: size20 },
        driver: { url: "/images/truck.png", scaledSize: size40 },
        destActive: { url: "/images/gps_green.png", scaledSize: size20 },
        destInactive: { url: "/images/gps_gray.png", scaledSize: size20 }
    };
}

/* MARKER UPDATE */
function updateMarker(key, pos, icon, label, labelColor, geocoder, infoWindow) {
    if (!markers[key]) {
        markers[key] = new google.maps.Marker({ position: pos, map, icon });
        markers[key].addListener("click", () => openInfoWindow(pos, geocoder, infoWindow, markers[key]));
    } else {
        markers[key].setPosition(pos);
        markers[key].setIcon(icon);
    }

    if (!overlays[key]) {
        overlays[key] = addMarkerLabel(map, pos, label, labelColor);
    } else {
        overlays[key].update(pos);
        overlays[key].updateLabel(label);
    }
}

/* INFO WINDOW */
function openInfoWindow(pos, geocoder, infoWindow, marker) {
    geocoder.geocode({ location: pos }, (results, status) => {
        if (status === "OK" && results[0]) {
            const r = results[0];
            infoWindow.setContent(`
                <div style="max-width: 250px;">
                    <b>${r.address_components[0].long_name}</b><br>
                    ${r.formatted_address}<br>
                    <a href="https://www.google.com/maps/search/?api=1&query=${pos.lat},${pos.lng}"
                        target="_blank" style="color:blue;">
                        View on Google Maps
                    </a>
                </div>
            `);
        } else {
            infoWindow.setContent("<b>Lokasi tidak ditemukan</b>");
        }
        infoWindow.open(map, marker);
    });
}

/* START + DRIVER */
function processStartAndDriver(data, geocoder, infoWindow, posList) {
    const add = (key, item, icon, label, color) => {
        if (!item?.lat || !item?.lng) return;
        const pos = parseLatLng(item);
        posList.push(pos);
        updateMarker(key, pos, icon, label, color, geocoder, infoWindow);
    };

    add("start", data.start_point, icons.start, "Start Point", "blue");
    add("driver", data.driver_position, icons.driver, "Driver", "red");
}

/* DESTINATIONS */
function processDestinations(data, geocoder, infoWindow, posList) {
    const list = [];

    if (!data.destination) return list;

    Object.entries(data.destination).forEach(([key, dest]) => {
        if (!dest.lat || !dest.lng) return;

        if (dest.status === "done") {
            cleanup(key);
            return;
        }

        const pos = parseLatLng(dest);
        list.push(pos);
        posList.push(pos);

        const label = key.replace(/dest(\d+)/i, "Destination $1");

        updateMarker(key, pos, icons.destInactive, label, "gray", geocoder, infoWindow);
    });

    return list;
}

function cleanup(key) {
    markers[key]?.setMap(null);
    overlays[key]?.setMap(null);
    delete markers[key];
    delete overlays[key];
}

/* PROCESS FASTEST DEST */
async function processFastestRoute(driverPosRaw, destList) {
    const driverPos = new google.maps.LatLng(driverPosRaw.lat, driverPosRaw.lng);
    let best = null;

    for (const dest of destList) {
        try {
            const r = await calcETA(driverPos, dest);
            if (!best || r.eta < best.eta) best = r;
        } catch {}
    }

    if (!best) return;

    highlightDestination(best.dest);

    const leg = best.result.routes[0].legs[0];
    overlays["driver"].updateLabel(`Driver\nDistance: ${leg.distance.text}\nETA: ${leg.duration_in_traffic?.text || leg.duration.text}`);

    drawTrafficColoredRoute(best.result);
    lastDriverPosition = driverPos;
}

/* ETA */
function calcETA(origin, dest) {
    return new Promise((resolve, reject) => {
        directionsService.route({
            origin,
            destination: dest,
            travelMode: "DRIVING",
            drivingOptions: { departureTime: new Date(), trafficModel: "optimistic" }
        }, (result, status) => {
            if (status !== "OK") return reject();
            const leg = result.routes[0].legs[0];
            resolve({ eta: leg.duration_in_traffic?.value || leg.duration.value, result, dest });
        });
    });
}

/* HIGHLIGHT ACTIVE DEST */
function highlightDestination(active) {
    for (const key in markers) {
        if (!key.startsWith("dest")) continue;

        const marker = markers[key];
        const overlay = overlays[key];

        const isActive =
            marker.getPosition().lat() === active.lat &&
            marker.getPosition().lng() === active.lng;

        marker.setIcon(isActive ? icons.destActive : icons.destInactive);
        overlay.updateLabel(isActive ? `${overlay.currentText} (Active)` : overlay.currentText.replace(" (Active)", ""));
        overlay.div.style.color = isActive ? "green" : "gray";
    }
}

/* ROUTE DRAW */
function drawTrafficColoredRoute(result) {
    const legs = result.routes[0].legs;

    legs.forEach(leg => {
        leg.steps.forEach(step => {
            const speed = step.distance.value / step.duration.value;

            const color =
                speed < 4 ? "red" :
                speed < 8 ? "orange" :
                "green";

            const poly = new google.maps.Polyline({
                path: step.path,
                strokeColor: color,
                strokeOpacity: 0.9,
                strokeWeight: 5,
                map
            });

            polylines.push(poly);
        });
    });
}

function resetPolylines() {
    polylines.forEach(l => l.setMap(null));
    polylines = [];
}

function fitMapBounds(list) {
    if (!list.length || lastDriverPosition) return;

    const bounds = new google.maps.LatLngBounds();
    list.forEach(p => bounds.extend(p));
    map.fitBounds(bounds);
}

/* UTIL */
function parseLatLng(o) {
    return { lat: parseFloat(o.lat), lng: parseFloat(o.lng) };
}

/* LABEL OVERLAY */
function addMarkerLabel(map, position, text, color = "blue") {
    const div = document.createElement("div");
    div.innerText = text;
    div.style.cssText = `
        position:absolute;font-weight:bold;font-size:14px;
        background:rgba(255,255,255,0.8);padding:2px 4px;border-radius:4px;
        white-space:pre-line;text-shadow:
            -2px -2px 0 #fff,2px -2px 0 #fff,-2px 2px 0 #fff,2px 2px 0 #fff;
        transform:translate(-110%,-100%);
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
        const p = proj.fromLatLngToDivPixel(new google.maps.LatLng(position.lat, position.lng));
        div.style.left = `${p.x}px`;
        div.style.top = `${p.y}px`;
    };

    overlay.update = function(pos) {
        position = pos;
        this.draw();
    };

    overlay.updateLabel = function(newText) {
        div.innerText = newText;
        this.currentText = newText;
    };

    overlay.onRemove = () => div.remove();
    overlay.setMap(map);

    return overlay;
}
</script>
@endpush

@section('content')
<div id="map" style="width:100%;height:100vh;"></div>
@endsection
