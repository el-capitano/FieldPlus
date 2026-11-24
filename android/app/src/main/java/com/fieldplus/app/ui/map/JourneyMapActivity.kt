package com.fieldplus.app.ui.map

import android.annotation.SuppressLint
import android.graphics.Color
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.webkit.JavascriptInterface
import android.webkit.WebChromeClient
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.Toast
import androidx.activity.SystemBarStyle
import androidx.activity.enableEdgeToEdge
import androidx.appcompat.app.AppCompatActivity
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import com.fieldplus.app.R
import com.fieldplus.app.data.model.JourneyData
import com.fieldplus.app.data.model.JourneyGeoUtils
import com.fieldplus.app.data.model.JourneyPoint
import com.fieldplus.app.databinding.ActivityJourneyMapBinding
import com.fieldplus.app.databinding.ItemJourneyWaypointBinding
import com.fieldplus.app.util.MapHelper
import com.google.gson.Gson
import java.util.Locale

class JourneyMapActivity : AppCompatActivity() {

    private lateinit var binding: ActivityJourneyMapBinding
    private var journeyData: JourneyData? = null
    private val gson = Gson()

    override fun onCreate(savedInstanceState: Bundle?) {
        enableEdgeToEdge(
            statusBarStyle = SystemBarStyle.auto(Color.TRANSPARENT, Color.TRANSPARENT),
            navigationBarStyle = SystemBarStyle.auto(Color.TRANSPARENT, Color.TRANSPARENT)
        )
        super.onCreate(savedInstanceState)
        binding = ActivityJourneyMapBinding.inflate(layoutInflater)
        setContentView(binding.root)

        ViewCompat.setOnApplyWindowInsetsListener(binding.appBarLayout) { view, insets ->
            val systemBars = insets.getInsets(WindowInsetsCompat.Type.systemBars())
            view.setPadding(0, systemBars.top, 0, 0)
            insets
        }

        binding.toolbar.setNavigationOnClickListener {
            finish()
        }

        val jsonStr = intent.getStringExtra(EXTRA_JOURNEY_JSON)
        val formTitle = intent.getStringExtra(EXTRA_FORM_TITLE) ?: "Field Inspection"
        val fieldLabel = intent.getStringExtra(EXTRA_FIELD_LABEL) ?: "GPS Journey Track"

        binding.toolbar.title = "$fieldLabel • Directions"
        binding.toolbar.subtitle = formTitle

        if (!jsonStr.isNullOrBlank()) {
            try {
                journeyData = gson.fromJson(jsonStr, JourneyData::class.java)
            } catch (e: Exception) {
                e.printStackTrace()
            }
        }

        val data = journeyData
        if (data == null || data.points.isEmpty()) {
            Toast.makeText(this, "No valid GPS journey points available.", Toast.LENGTH_LONG).show()
            finish()
            return
        }

        setupSummaryStats(data)
        setupTimelineRecyclerView(data)
        setupWebViewMap(data)
        setupControls(data)
    }

    private fun setupSummaryStats(data: JourneyData) {
        val pts = data.points
        val startTime = data.startTime ?: pts.firstOrNull()?.timestamp ?: "--"
        val endTime = data.endTime ?: pts.lastOrNull()?.timestamp ?: "--"

        binding.tvStartSummary.text = "🏁 Start: $startTime"
        binding.tvEndSummary.text = "🛑 End: $endTime"

        val dist = data.distanceMeters
        val distStr = if (dist >= 1000.0) {
            String.format(Locale.US, "%.2f km", dist / 1000.0)
        } else {
            String.format(Locale.US, "%.0f m", dist)
        }
        binding.tvDistanceSummary.text = "$distStr (${pts.size} pts)"

        val durSec = data.durationSeconds
        val hours = durSec / 3600
        val mins = (durSec % 3600) / 60
        val secs = durSec % 60
        binding.tvDurationSummary.text = String.format(Locale.US, "Duration: %02d:%02d:%02d", hours, mins, secs)
    }

    private fun setupTimelineRecyclerView(data: JourneyData) {
        binding.rvWaypoints.layoutManager = LinearLayoutManager(this)
        val adapter = WaypointTimelineAdapter(data.points) { index ->
            // Highlight and pan map to selected point
            binding.webViewMap.evaluateJavascript("focusWaypoint($index);", null)
        }
        binding.rvWaypoints.adapter = adapter
    }

    @SuppressLint("SetJavaScriptEnabled")
    private fun setupWebViewMap(data: JourneyData) {
        val webView = binding.webViewMap
        webView.settings.javaScriptEnabled = true
        webView.settings.domStorageEnabled = true
        webView.settings.allowFileAccess = true
        webView.settings.builtInZoomControls = false
        webView.settings.displayZoomControls = false

        webView.addJavascriptInterface(object : Any() {
            @JavascriptInterface
            fun onMarkerClicked(index: Int) {
                runOnUiThread {
                    binding.cardWaypointList.visibility = View.VISIBLE
                    binding.rvWaypoints.smoothScrollToPosition(index)
                }
            }
        }, "AndroidBridge")

        webView.webChromeClient = WebChromeClient()
        webView.webViewClient = object : WebViewClient() {
            override fun onPageFinished(view: WebView?, url: String?) {
                binding.progressMapLoading.visibility = View.GONE
            }
        }

        val html = buildLeafletMapHtml(data)
        webView.loadDataWithBaseURL("https://openstreetmap.org", html, "text/html", "UTF-8", null)
    }

    private fun setupControls(data: JourneyData) {
        binding.fabFitAll.setOnClickListener {
            binding.webViewMap.evaluateJavascript("fitAll();", null)
        }

        binding.fabToggleTimeline.setOnClickListener {
            if (binding.cardWaypointList.visibility == View.VISIBLE) {
                binding.cardWaypointList.visibility = View.GONE
            } else {
                binding.cardWaypointList.visibility = View.VISIBLE
            }
        }

        binding.btnCloseTimeline.setOnClickListener {
            binding.cardWaypointList.visibility = View.GONE
        }

        binding.fabExternalMap.setOnClickListener {
            val first = data.points.firstOrNull() ?: return@setOnClickListener
            val pathPairs = data.points.map { Pair(it.lat, it.lng) }
            MapHelper.showMapProviderChooser(
                this,
                first.lat,
                first.lng,
                label = "Trip Track (${data.points.size} points)",
                pathPoints = pathPairs
            )
        }

        binding.fabExportTrack.setOnClickListener {
            val options = arrayOf(
                "📁 Export as GPX (Garmin / Strava / GPS)",
                "🌍 Export as KML (Google Earth)",
                "🗺️ Export as GeoJSON (QGIS / GIS Tools)"
            )
            androidx.appcompat.app.AlertDialog.Builder(this)
                .setTitle("Export & Share GPS Track")
                .setItems(options) { _, which ->
                    try {
                        val title = intent.getStringExtra(EXTRA_FIELD_LABEL) ?: "Journey_Track"
                        when (which) {
                            0 -> {
                                val file = com.fieldplus.app.util.GpsTrackExporter.exportToGpx(this, data, title)
                                com.fieldplus.app.util.GpsTrackExporter.shareTrackFile(this, file, "application/gpx+xml", "$title (GPX)")
                            }
                            1 -> {
                                val file = com.fieldplus.app.util.GpsTrackExporter.exportToKml(this, data, title)
                                com.fieldplus.app.util.GpsTrackExporter.shareTrackFile(this, file, "application/vnd.google-earth.kml+xml", "$title (KML)")
                            }
                            2 -> {
                                val file = com.fieldplus.app.util.GpsTrackExporter.exportToGeoJson(this, data, title)
                                com.fieldplus.app.util.GpsTrackExporter.shareTrackFile(this, file, "application/geo+json", "$title (GeoJSON)")
                            }
                        }
                    } catch (e: Exception) {
                        Toast.makeText(this, "Export failed: ${e.message}", Toast.LENGTH_SHORT).show()
                    }
                }
                .setNegativeButton("Cancel", null)
                .show()
        }
    }

    private fun buildLeafletMapHtml(data: JourneyData): String {
        val pointsJson = gson.toJson(data.points)
        val totalDist = data.distanceMeters

        return """
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8" />
            <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
            <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
            <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
            <style>
                html, body, #map { height: 100%; width: 100%; margin: 0; padding: 0; }
                .leaflet-popup-content-wrapper { border-radius: 12px; box-shadow: 0 4px 14px rgba(0,0,0,0.25); }
                .leaflet-popup-content { margin: 12px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
                .start-pin {
                    background: #16A34A;
                    color: #fff;
                    font-weight: bold;
                    font-size: 11px;
                    padding: 3px 7px;
                    border-radius: 12px;
                    border: 2px solid #fff;
                    box-shadow: 0 2px 6px rgba(0,0,0,0.35);
                    white-space: nowrap;
                }
                .end-pin {
                    background: #DC2626;
                    color: #fff;
                    font-weight: bold;
                    font-size: 11px;
                    padding: 3px 7px;
                    border-radius: 12px;
                    border: 2px solid #fff;
                    box-shadow: 0 2px 6px rgba(0,0,0,0.35);
                    white-space: nowrap;
                }
                .arrow-wrap {
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    cursor: pointer;
                    filter: drop-shadow(0 2px 4px rgba(0,0,0,0.35));
                }
            </style>
        </head>
        <body>
            <div id="map"></div>
            <script>
                const waypoints = $pointsJson;
                const totalDistMeters = $totalDist;

                const map = L.map('map', {
                    zoomControl: false,
                    attributionControl: false
                }).setView([24.7136, 46.6753], 15);

                L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19
                }).addTo(map);

                const markers = [];

                if (waypoints && waypoints.length > 0) {
                    const latLngs = waypoints.map(p => [p.lat, p.lng]);

                    // Outer casing for contrast
                    L.polyline(latLngs, {
                        color: '#FFFFFF',
                        weight: 7,
                        opacity: 0.9,
                        lineCap: 'round',
                        lineJoin: 'round'
                    }).addTo(map);

                    // Main route polyline
                    const mainPolyline = L.polyline(latLngs, {
                        color: '#2563EB',
                        weight: 4.5,
                        opacity: 0.95,
                        lineCap: 'round',
                        lineJoin: 'round'
                    }).addTo(map);

                    // Calculate bearing helper
                    function getBearing(lat1, lon1, lat2, lon2) {
                        const y = Math.sin((lon2 - lon1) * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180);
                        const x = Math.cos(lat1 * Math.PI / 180) * Math.sin(lat2 * Math.PI / 180) -
                                  Math.sin(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) * Math.cos((lon2 - lon1) * Math.PI / 180);
                        return (Math.atan2(y, x) * 180 / Math.PI + 360) % 360;
                    }

                    // Helper to parse timestamp
                    function parseTime(ts) {
                        if (!ts) return null;
                        const d = Date.parse(ts);
                        if (!isNaN(d)) return d;
                        const parts = ts.split(/[- :T]/);
                        if (parts.length >= 6) {
                            return new Date(parts[0], parts[1]-1, parts[2], parts[3], parts[4], parts[5]).getTime();
                        }
                        return null;
                    }

                    // Identify 1-min interval milestones (leave the rest for the log)
                    let lastMapTime = parseTime(waypoints[0].timestamp);
                    let minuteCounter = 1;
                    const mapWaypoints = {};
                    mapWaypoints[0] = { minute: 0 };

                    for (let i = 1; i < waypoints.length; i++) {
                        const t = parseTime(waypoints[i].timestamp);
                        if (t && lastMapTime && (t - lastMapTime) >= 58000) {
                            mapWaypoints[i] = { minute: minuteCounter++ };
                            lastMapTime = t;
                        }
                    }
                    mapWaypoints[waypoints.length - 1] = { minute: minuteCounter };

                    // Start Marker
                    const startPt = waypoints[0];
                    const startMarker = L.marker([startPt.lat, startPt.lng], {
                        icon: L.divIcon({
                            className: 'start-marker-icon',
                            html: '<div class="start-pin">🏁 Start: ' + (startPt.timestamp || '') + '</div>',
                            iconAnchor: [35, 14]
                        })
                    }).addTo(map);

                    startMarker.bindPopup(
                        '<div style="font-size:13px;line-height:1.5;">' +
                        '<b style="color:#16A34A;font-size:14px;">🏁 Journey Started</b><br/>' +
                        '<b>🕒 Time:</b> ' + (startPt.timestamp || 'N/A') + '<br/>' +
                        '<b>🌐 Coords:</b> ' + startPt.lat.toFixed(6) + ', ' + startPt.lng.toFixed(6) + '<br/>' +
                        '<b>🎯 Accuracy:</b> ±' + (startPt.accuracy ? startPt.accuracy.toFixed(1) + 'm' : 'N/A') + '<br/>' +
                        '<b>⛰️ Altitude:</b> ' + (startPt.alt ? startPt.alt.toFixed(1) + 'm' : 'N/A') +
                        '</div>'
                    );
                    markers[0] = startMarker;

                    // Intermediate Waypoint Direction Arrows (1-min intervals only)
                    for (let i = 1; i < waypoints.length - 1; i++) {
                        if (!mapWaypoints[i]) continue; // Show 1 min waypoints only, leave rest for log

                        const pt = waypoints[i];
                        const prev = waypoints[i - 1];
                        const b = pt.bearing != null ? pt.bearing : getBearing(prev.lat, prev.lng, pt.lat, pt.lng);
                        const arrowEmoji = pt.arrow || '↗';
                        const dirText = pt.direction || (Math.round(b) + '°');
                        const distFromPrev = pt.distance_from_prev != null ? Math.round(pt.distance_from_prev) : 0;
                        const speedText = pt.speed != null ? Math.round(pt.speed * 3.6) + ' km/h' : '--';
                        const minMark = mapWaypoints[i].minute;

                        // Create directional SVG arrow rotated to travel heading
                        const arrowHtml = 
                            '<div class="arrow-wrap" style="transform: rotate(' + b + 'deg); width:28px; height:28px;" onclick="AndroidBridge.onMarkerClicked(' + i + ')">' +
                            '  <svg width="24" height="24" viewBox="0 0 24 24">' +
                            '    <path d="M12 2L4.5 20.29l.71.71L12 18l6.79 3 .71-.71z" fill="#0284C7" stroke="#FFFFFF" stroke-width="2"/>' +
                            '  </svg>' +
                            '</div>';

                        const arrowMarker = L.marker([pt.lat, pt.lng], {
                            icon: L.divIcon({
                                className: 'waypoint-arrow-icon',
                                html: arrowHtml,
                                iconSize: [28, 28],
                                iconAnchor: [14, 14]
                            })
                        }).addTo(map);

                        const popupContent = 
                            '<div style="font-size:13px;line-height:1.5;">' +
                            '  <strong style="color:#0284C7;font-size:14px;">📍 1-Min Waypoint #' + (i + 1) + ' (' + minMark + ' min)</strong><br/>' +
                            '  <b>🕒 Registered:</b> ' + (pt.timestamp || 'N/A') + '<br/>' +
                            '  <b>🧭 Direction Taken:</b> ' + arrowEmoji + ' ' + dirText + ' (' + Math.round(b) + '°)<br/>' +
                            '  <b>📏 Distance from prev:</b> +' + distFromPrev + 'm<br/>' +
                            '  <b>⚡ Speed:</b> ' + speedText + '<br/>' +
                            '  <b>🎯 Accuracy:</b> ±' + (pt.accuracy ? pt.accuracy.toFixed(1) + 'm' : 'N/A') + '<br/>' +
                            '  <span style="font-size:11px;color:#64748B;">' + pt.lat.toFixed(6) + ', ' + pt.lng.toFixed(6) + '</span>' +
                            '</div>';

                        arrowMarker.bindPopup(popupContent);
                        markers[i] = arrowMarker;
                    }

                    // End Marker (if > 1 point)
                    if (waypoints.length > 1) {
                        const endIdx = waypoints.length - 1;
                        const endPt = waypoints[endIdx];
                        const endMarker = L.marker([endPt.lat, endPt.lng], {
                            icon: L.divIcon({
                                className: 'end-marker-icon',
                                html: '<div class="end-pin">🛑 End: ' + (endPt.timestamp || '') + '</div>',
                                iconAnchor: [35, 14]
                            })
                        }).addTo(map);

                        endMarker.bindPopup(
                            '<div style="font-size:13px;line-height:1.5;">' +
                            '<b style="color:#DC2626;font-size:14px;">🛑 Journey Finished</b><br/>' +
                            '<b>🕒 Ended:</b> ' + (endPt.timestamp || 'N/A') + '<br/>' +
                            '<b>🏁 Total Distance:</b> ' + (totalDistMeters >= 1000 ? (totalDistMeters / 1000).toFixed(2) + ' km' : totalDistMeters.toFixed(0) + ' m') + '<br/>' +
                            '<b>📍 Total Waypoints:</b> ' + waypoints.length + ' logged<br/>' +
                            '<b>🌐 Coords:</b> ' + endPt.lat.toFixed(6) + ', ' + endPt.lng.toFixed(6) +
                            '</div>'
                        );
                        markers[endIdx] = endMarker;
                    }

                    map.fitBounds(mainPolyline.getBounds(), { padding: [40, 40] });
                }

                function fitAll() {
                    if (waypoints && waypoints.length > 0) {
                        const latLngs = waypoints.map(p => [p.lat, p.lng]);
                        map.fitBounds(L.polyline(latLngs).getBounds(), { padding: [40, 40] });
                    }
                }

                let transientMarker = null;
                function focusWaypoint(index) {
                    if (waypoints && index >= 0 && index < waypoints.length) {
                        const pt = waypoints[index];
                        map.setView([pt.lat, pt.lng], 17);
                        if (markers[index]) {
                            markers[index].openPopup();
                        } else {
                            if (transientMarker) map.removeLayer(transientMarker);
                            transientMarker = L.circleMarker([pt.lat, pt.lng], {
                                radius: 8,
                                color: '#F59E0B',
                                fillColor: '#FEF3C7',
                                fillOpacity: 0.9,
                                weight: 3
                            }).addTo(map);
                            const prev = index > 0 ? waypoints[index - 1] : null;
                            const b = pt.bearing != null ? pt.bearing : (prev ? getBearing(prev.lat, prev.lng, pt.lat, pt.lng) : 0);
                            const arrowEmoji = pt.arrow || '↗';
                            const dirText = pt.direction || (Math.round(b) + '°');
                            const distFromPrev = pt.distance_from_prev != null ? Math.round(pt.distance_from_prev) : 0;
                            const speedText = pt.speed != null ? Math.round(pt.speed * 3.6) + ' km/h' : '--';

                            transientMarker.bindPopup(
                                '<div style="font-size:13px;line-height:1.5;">' +
                                '  <strong style="color:#D97706;font-size:14px;">📝 Fix #' + (index + 1) + ' (Telematics Log)</strong><br/>' +
                                '  <b>🕒 Registered:</b> ' + (pt.timestamp || 'N/A') + '<br/>' +
                                '  <b>🧭 Direction Taken:</b> ' + arrowEmoji + ' ' + dirText + ' (' + Math.round(b) + '°)<br/>' +
                                '  <b>📏 Leg Distance:</b> +' + distFromPrev + 'm<br/>' +
                                '  <b>⚡ Speed:</b> ' + speedText + '<br/>' +
                                '  <span style="font-size:11px;color:#64748B;">' + pt.lat.toFixed(6) + ', ' + pt.lng.toFixed(6) + '</span>' +
                                '</div>'
                            ).openPopup();
                        }
                    }
                }
            </script>
        </body>
        </html>
        """.trimIndent()
    }

    private class WaypointTimelineAdapter(
        private val points: List<JourneyPoint>,
        private val onPointClick: (Int) -> Unit
    ) : RecyclerView.Adapter<WaypointTimelineAdapter.ViewHolder>() {

        class ViewHolder(val binding: ItemJourneyWaypointBinding) : RecyclerView.ViewHolder(binding.root)

        override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): ViewHolder {
            val binding = ItemJourneyWaypointBinding.inflate(LayoutInflater.from(parent.context), parent, false)
            return ViewHolder(binding)
        }

        override fun onBindViewHolder(holder: ViewHolder, position: Int) {
            val pt = points[position]
            val binding = holder.binding

            binding.tvPointIndex.text = "#${position + 1}"
            if (position == 0) {
                binding.tvPointIndex.backgroundTintList = android.content.res.ColorStateList.valueOf(Color.parseColor("#16A34A"))
                binding.tvPointHeading.text = "🏁 Starting Point"
                binding.tvPointHeading.setTextColor(Color.parseColor("#16A34A"))
            } else if (position == points.size - 1) {
                binding.tvPointIndex.backgroundTintList = android.content.res.ColorStateList.valueOf(Color.parseColor("#DC2626"))
                val dir = pt.direction ?: pt.bearing?.let { JourneyGeoUtils.getDirectionLabel(it) } ?: ""
                val arrow = pt.arrow ?: pt.bearing?.let { JourneyGeoUtils.getDirectionArrow(it) } ?: "🛑"
                binding.tvPointHeading.text = "🛑 Final Destination ($arrow $dir)"
                binding.tvPointHeading.setTextColor(Color.parseColor("#DC2626"))
            } else {
                binding.tvPointIndex.backgroundTintList = android.content.res.ColorStateList.valueOf(Color.parseColor("#0284C7"))
                val dir = pt.direction ?: pt.bearing?.let { JourneyGeoUtils.getDirectionLabel(it) } ?: "Bearing"
                val arrow = pt.arrow ?: pt.bearing?.let { JourneyGeoUtils.getDirectionArrow(it) } ?: "↗"
                val deg = pt.bearing?.let { " (${Math.round(it)}°)" } ?: ""
                binding.tvPointHeading.text = "$arrow Heading $dir$deg"
                binding.tvPointHeading.setTextColor(holder.itemView.context.getColor(R.color.text_primary))
            }

            binding.tvPointTimestamp.text = pt.timestamp ?: "--"

            val distFromPrev = pt.distanceFromPrev
            if (position == 0 || distFromPrev == null) {
                binding.tvPointDistance.text = "Trip origin"
            } else {
                binding.tvPointDistance.text = String.format(Locale.US, "+%.0fm from prev", distFromPrev)
            }

            val speedText = pt.speed?.let { String.format(Locale.US, "%.0f km/h", it * 3.6f) } ?: "--"
            val accText = pt.accuracy?.let { String.format(Locale.US, "±%.1fm", it) } ?: "--"
            binding.tvPointSpeedAccuracy.text = "$speedText • $accText"

            binding.tvPointCoords.text = String.format(Locale.US, "%.6f, %.6f", pt.lat, pt.lng)

            holder.itemView.setOnClickListener {
                onPointClick(position)
            }
        }

        override fun getItemCount(): Int = points.size
    }

    companion object {
        const val EXTRA_JOURNEY_JSON = "extra_journey_json"
        const val EXTRA_FORM_TITLE = "extra_form_title"
        const val EXTRA_FIELD_LABEL = "extra_field_label"
    }
}
