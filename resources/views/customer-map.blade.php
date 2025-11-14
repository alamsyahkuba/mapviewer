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

		setupClickListener();
	}

	function setupClickListener() {
        map.addListener('click', (event) => {
        	const clicked = event.latLng;
            const lat = event.latLng.lat();
            const lng = event.latLng.lng();
    
            if (!marker) {
                marker = new google.maps.Marker({ position: event.latLng, map });
            } else {
                marker.setPosition(event.latLng);
            }

            geocoder.geocode({ location: clicked }, function (results, status) {
                let address = "";
                
                if (status === "OK" && results[0]) {
                    address = results[0].formatted_address;
                } else {
                    console.warn("Geocode failed:", status);
                }

                const jsonData = {
                    type: "click",
                    lat: lat.toFixed(8),
                    lng: lng.toFixed(8),
                    address: address,
                    timestamp: new Date().toISOString()
                };
				const jsonString = JSON.stringify(jsonData);
                console.log("Map click:", JSON.stringify(jsonData));

             	// --- Priority: send JSON directly to CEF ---
             	sendJsonToCEF(jsonString);
            	 // --- Fallback: send to Unity through old bridge ---
                sendLatLngToUnity(lat, lng);
            });
        });
    }

    function searchAddress() {
        const input = document.getElementById('addressInput').value;
        if (!input) return;

        geocoder.geocode( {address: input}, function(result, status) {
			if (status === "OK" && result[0]) {
				const location = result[0].geometry.location;
				const lat = location.lat();
				const lng = location.lng();

				map.setCenter(location);
				map.setZoom(18);

				if (!marker) {
					marker = new google.maps.Marker({ position: location, map });
				} else {
					marker.setPosition(location);
				}

				const jsonData = {
					type: "search",
					lat: lat,
					lng: lng,
					address: result[0].formatted_address,
					timestamp: new Date().toISOString()
				};
				const jsonString = JSON.stringify(jsonData);
				console.log("Map search:", JSON.stringify(jsonData));
				
				// --- Priority: send JSON directly to CEF ---
             	sendJsonToCEF(jsonString);
            	 // --- Fallback: send to Unity through old bridge ---
                sendLatLngToUnity(lat, lng);
			} else {
				showAlert("Geocode failed: " + status);
			}
        });
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

    function sendLatLngToUnity(lat, lng) {
        if (typeof window.__cef_sendLatLngToUnity === "function") {
            window.__cef_sendLatLngToUnity(lat, lng);
            console.log("Sent to Unity via CEF:", lat, lng);
        } else {
            console.log("Unity bridge not ready:", lat, lng);
        }
    }

    function sendJsonToCEF(jsonString) {
    	if (typeof window.__cef_sendJsonToCEF === "function") {
            try {
                window.__cef_sendJsonToCEF(jsonString);
                console.log("[Bridge] Sent JSON to CEF successfully.");
            } catch (err) {
                console.error("[Bridge] Failed to send JSON to CEF:", err);
            }
        }
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
