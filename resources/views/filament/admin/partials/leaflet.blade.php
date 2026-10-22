{{-- Leaflet and the map components for the admin pages that draw maps
     (Delivery settings, an order). Loaded in <head> through a render hook
     scoped to those pages: <script> inside a Livewire-rendered modal never
     runs, so the components must already exist when a modal opens.

     Leaflet pinned with SRI. OpenStreetMap tiles: free, no key, attribution
     shown, fetched only as a map is looked at — never in bulk. --}}
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<script>
    window.gpMaps = {
        // The same green / amber / grey as the rule badges (DeliveryRule::COLORS).
        COLORS: { free: '#16a34a', fee: '#d97706', not_delivered: '#9ca3af' },
        NIGERIA: [9.08, 8.68],

        tiles(map) {
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            }).addTo(map);
        },

        // A map drawn inside a modal or a just-revealed card has no size yet.
        settle(map) {
            [50, 250, 600].forEach((ms) => setTimeout(() => map.invalidateSize(), ms));
        },

        pin(color) {
            return L.divIcon({
                className: 'gp-map-pin',
                html: '<span style="background:' + color + '"></span>',
                iconSize: [22, 22],
                iconAnchor: [11, 22],
            });
        },
    };

    document.addEventListener('alpine:init', () => {
        // Delivery settings: every LGA coloured by its rule; tap one to edit it.
        window.Alpine.data('gpDeliveryMap', (initial) => ({
            data: initial,
            stateFilter: '',
            onlyDelivered: false,
            showRings: false,
            map: null,
            lgaLayer: null,
            hubLayer: null,
            ringLayer: null,

            init() {
                this.map = L.map(this.$refs.map, { preferCanvas: true }).setView(gpMaps.NIGERIA, 6);
                gpMaps.tiles(this.map);
                gpMaps.settle(this.map);

                this.lgaLayer = L.layerGroup().addTo(this.map);
                this.hubLayer = L.layerGroup().addTo(this.map);
                this.ringLayer = L.layerGroup();

                this.draw();
                this.$watch('stateFilter', () => { this.draw(); this.zoomToState(); });
                this.$watch('onlyDelivered', () => this.draw());
                this.$watch('showRings', () => this.drawRings());
            },

            refresh(data) {
                this.data = data;
                this.draw();
            },

            draw() {
                this.lgaLayer.clearLayers();
                this.hubLayer.clearLayers();

                this.data.lgas
                    .filter((l) => ! this.stateFilter || l.state_id === Number(this.stateFilter))
                    .filter((l) => ! this.onlyDelivered || l.mode !== 'not_delivered')
                    .forEach((l) => {
                        // A generous invisible hit area: an LGA dot is easy to tap.
                        const marker = L.circleMarker([l.lat, l.lng], {
                            radius: 7, weight: 1, color: '#1f2937',
                            fillColor: gpMaps.COLORS[l.mode], fillOpacity: 0.9,
                        });
                        marker.bindTooltip(l.name + ' — ' + l.label);
                        marker.on('click', () => this.$wire.mountAction('editLga', { lga: l.id }));
                        marker.addTo(this.lgaLayer);
                    });

                this.data.hubs.forEach((h) => {
                    L.marker([h.lat, h.lng], { title: h.name, icon: gpMaps.pin('#2563eb') })
                        .bindTooltip(h.name + ' (store hub)')
                        .addTo(this.hubLayer);
                });

                this.drawRings();
            },

            drawRings() {
                this.ringLayer.clearLayers();
                if (! this.showRings) { this.map.removeLayer(this.ringLayer); return; }
                this.data.hubs.forEach((h) => [10, 25, 50, 100].forEach((km) => {
                    L.circle([h.lat, h.lng], { radius: km * 1000, color: '#2563eb', weight: 1, dashArray: '4 6', fill: false })
                        .bindTooltip(km + ' km straight-line').addTo(this.ringLayer);
                }));
                this.ringLayer.addTo(this.map);
            },

            zoomToState() {
                const points = this.data.lgas.filter((l) => l.state_id === Number(this.stateFilter)).map((l) => [l.lat, l.lng]);
                if (points.length) this.map.fitBounds(points, { padding: [30, 30] });
                else this.map.setView(gpMaps.NIGERIA, 6);
            },
        }));

        // "Pin on map": tap or drag to place a store, or use this phone's location.
        // Writes into the action form's latitude / longitude fields; nothing is
        // saved until the form is.
        window.Alpine.data('gpPinMap', () => ({
            latPath: null,
            lngPath: null,
            map: null,
            marker: null,
            locating: false,
            error: null,

            init() {
                this.latPath = this.$root.dataset.latPath;
                this.lngPath = this.$root.dataset.lngPath;

                const lat = parseFloat(this.$wire.$get(this.latPath));
                const lng = parseFloat(this.$wire.$get(this.lngPath));
                const has = ! isNaN(lat) && ! isNaN(lng);

                this.map = L.map(this.$refs.map).setView(has ? [lat, lng] : gpMaps.NIGERIA, has ? 16 : 6);
                gpMaps.tiles(this.map);
                gpMaps.settle(this.map);

                if (has) this.place(lat, lng, false);

                this.map.on('click', (e) => this.place(e.latlng.lat, e.latlng.lng, true));

                // Typed coordinates move the pin too.
                this.$wire.$watch(this.latPath, () => this.follow());
                this.$wire.$watch(this.lngPath, () => this.follow());
            },

            place(lat, lng, write) {
                if (! this.marker) {
                    this.marker = L.marker([lat, lng], { draggable: true, icon: gpMaps.pin('#2563eb') }).addTo(this.map);
                    this.marker.on('dragend', () => {
                        const p = this.marker.getLatLng();
                        this.write(p.lat, p.lng);
                    });
                } else {
                    this.marker.setLatLng([lat, lng]);
                }

                if (write) this.write(lat, lng);
            },

            write(lat, lng) {
                this.$wire.$set(this.latPath, Number(lat.toFixed(6)), false);
                this.$wire.$set(this.lngPath, Number(lng.toFixed(6)), false);
            },

            follow() {
                const lat = parseFloat(this.$wire.$get(this.latPath));
                const lng = parseFloat(this.$wire.$get(this.lngPath));
                if (! isNaN(lat) && ! isNaN(lng)) this.place(lat, lng, false);
            },

            locate() {
                this.error = null;

                if (! navigator.geolocation) {
                    this.error = 'This browser can\'t share its location.';
                    return;
                }

                this.locating = true;
                navigator.geolocation.getCurrentPosition(
                    (pos) => {
                        this.locating = false;
                        this.place(pos.coords.latitude, pos.coords.longitude, true);
                        this.map.setView([pos.coords.latitude, pos.coords.longitude], 17);
                    },
                    () => {
                        this.locating = false;
                        this.error = 'Couldn\'t get your location. Allow location access, or tap the map instead.';
                    },
                    { enableHighAccuracy: true, timeout: 15000 },
                );
            },
        }));
    });
</script>
