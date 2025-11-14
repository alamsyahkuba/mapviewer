<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Customer Map</title>
    
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

                    const payload = {
                        type: "click",
                        lat: lat.toFixed(8),
                        lng: lng.toFixed(8),
                        address: address,
                        timestamp: new Date().toISOString()
                    };

                    console.log("Map click:", JSON.stringify(payload));
                    sendLatLngToUnity(lat, lng);
                });
            });
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

        window.onload = initMap;
    </script>
</head>
<body style="margin:0;padding:0">
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
	
</body>
</html>