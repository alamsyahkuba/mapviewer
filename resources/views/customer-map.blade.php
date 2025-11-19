@extends('index')
@section('title', 'Customer Map')

@push('scripts')
<script src="https://maps.googleapis.com/maps/api/js?key={{ env('GOOGLE_MAPS_API_KEY') }}"></script>
<script>
    let map, marker;
    const geocoder = new google.maps.Geocoder();

    async function initMap() {
        map = new google.maps.Map(document.getElementById('map'), {
            center: { lat: -7.9666, lng: 112.6326 },
            zoom: 13,
            gestureHandling: "greedy",
            streetViewControl: false,
            fullscreenControl: true,
            mapTypeControl: true,
            styles: [{ featureType: "poi", stylers: [{ visibility: "off" }] }]
        });

        map.addListener('click', handleMapClick);
    }

    function setMarker(position) {
        if (!marker) {
            marker = new google.maps.Marker({ position, map });
        } else {
            marker.setPosition(position);
        }
    }

    function sendAll(jsonData) {
        const jsonString = JSON.stringify(jsonData);
        console.log("[Map Event]:", jsonString);

        if (typeof window.__cef_sendJsonToCEF === "function") {
            try {
                window.__cef_sendJsonToCEF(jsonString);
                console.log("[Bridge] JSON sent to CEF");
            } catch (err) {
                console.error("[Bridge] Failed:", err);
            }
        }

        if (typeof window.__cef_sendLatLngToUnity === "function") {
            window.__cef_sendLatLngToUnity(jsonData.lat, jsonData.lng);
        }
    }

    function geocodeAsync(request) {
        return new Promise((resolve, reject) => {
            geocoder.geocode(request, (results, status) => {
                if (status === "OK" && results[0]) return resolve(results[0]);
                reject(status);
            });
        });
    }

    async function handleMapClick(event) {
        const lat = event.latLng.lat();
        const lng = event.latLng.lng();
        setMarker(event.latLng);

        let address = "";
        try {
            const result = await geocodeAsync({ location: event.latLng });
            address = result.formatted_address;
        } catch (status) {
            console.warn("Geocode failed:", status);
        }

        sendAll({
            type: "click",
            lat: lat.toFixed(8),
            lng: lng.toFixed(8),
            address,
            timestamp: new Date().toISOString()
        });
    }

    async function searchAddress() {
        const input = document.getElementById('addressInput').value;
        if (!input) return;

        try {
            const result = await geocodeAsync({ address: input });
            const location = result.geometry.location;

            map.setCenter(location);
            map.setZoom(18);
            setMarker(location);

            sendAll({
                type: "search",
                lat: location.lat(),
                lng: location.lng(),
                address: result.formatted_address,
                timestamp: new Date().toISOString()
            });
        } catch (status) {
            showAlert("Geocode failed: " + status);
        }
    }

    function clearSearchAddress() {
        document.getElementById('addressInput').value = "";
    }

    function showAlert(message) {
        const alertBox = document.getElementById("customAlert");
        document.getElementById("alertMessage").innerText = message;
        alertBox.style.display = "block";
    }
    function closeAlert() {
        document.getElementById("customAlert").style.display = "none";
    }

    window.onload = initMap;
</script>
@endpush

@push('styles')
<style type="text/css">
    #searchContainer {
        position: absolute;
        top: 10px;
        left: 50%;
        transform: translateX(-50%);
        z-index: 5;
        background: white;
        padding: 10px;
        border-radius: 5px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.3);
        display: flex;
        gap: 5px;
    }
    #addressInput {
        width: 300px;
        padding: 5px;
    }
    #searchButton {
        padding: 5px 10px;
        background-color: #217AFA;
        color: white;
        border: none;
        border-radius: 4px;
    }
    #clearSearchButton {
        padding: 5px 10px;
        border: none;
        border-radius: 4px;
    }
</style>
@endpush

@section('content')
<div id="searchContainer">
	<input type="text" id="addressInput" placeholder="Search Address..."/>
	<button id="searchButton" onclick="searchAddress()">Search</button>
	<button id="clearSearchButton" onclick="clearSearchAddress()">Clear</button>
</div>
<div id="map" style="width:100%;height:100vh;"></div>

<div id="customAlert" style="display:none; position:fixed; top:50%; left:50%;
    transform:translate(-50%, -50%); 
    background:#2c3e50; color:#ecf0f1; padding:30px 40px; 
    border-radius:12px; box-shadow:0 8px 20px rgba(0,0,0,0.4);
    text-align:center; z-index:1000; font-family:Arial, sans-serif; max-width:90%; width:300px;">
    <span id="alertMessage" style="display:block; margin-bottom:20px; font-size:16px; font-weight:bold;"></span>
    <button onclick="closeAlert()" style="padding:10px 25px; 
        background:#e74c3c; color:#fff; border:none; border-radius:6px;
        cursor:pointer; font-size:14px; transition:0.3s;">OK</button>
</div>
@endsection
