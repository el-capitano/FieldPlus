package com.fieldplus.app.util

import android.content.Context
import android.content.Intent
import androidx.core.content.FileProvider
import com.fieldplus.app.data.model.JourneyData
import com.fieldplus.app.data.model.JourneyPoint
import com.google.gson.GsonBuilder
import java.io.File
import java.io.FileOutputStream
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

object GpsTrackExporter {

    private val sdfFile = SimpleDateFormat("yyyyMMdd_HHmmss", Locale.US)

    /**
     * Exports JourneyData to standard GPX 1.1 format (compatible with Garmin, Strava, Google Earth, QGIS).
     */
    fun exportToGpx(context: Context, data: JourneyData, title: String): File {
        val fileName = "Track_${sanitizeFileName(title)}_${sdfFile.format(Date())}.gpx"
        val exportDir = File(context.cacheDir, "exports").apply { mkdirs() }
        val file = File(exportDir, fileName)

        val sb = StringBuilder()
        sb.append("""<?xml version="1.0" encoding="UTF-8"?>""").append("\n")
        sb.append("""<gpx version="1.1" creator="FieldPlus Native Android"""")
        sb.append(""" xmlns="http://www.topografix.com/GPX/1/1"""")
        sb.append(""" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"""")
        sb.append(""" xsi:schemaLocation="http://www.topografix.com/GPX/1/1 http://www.topografix.com/GPX/1/1/gpx.xsd">""").append("\n")

        sb.append("  <metadata>\n")
        sb.append("    <name>").append(escapeXml(title)).append("</name>\n")
        sb.append("    <time>").append(data.startTime ?: sdfFile.format(Date())).append("</time>\n")
        sb.append("  </metadata>\n")

        // First point as start waypoint
        data.points.firstOrNull()?.let { start ->
            sb.append("  <wpt lat=\"").append(start.lat).append("\" lon=\"").append(start.lng).append("\">\n")
            sb.append("    <name>🏁 Start: ").append(escapeXml(start.timestamp ?: "")).append("</name>\n")
            start.alt?.let { sb.append("    <ele>").append(it).append("</ele>\n") }
            sb.append("  </wpt>\n")
        }

        // Last point as end waypoint
        if (data.points.size > 1) {
            val end = data.points.last()
            sb.append("  <wpt lat=\"").append(end.lat).append("\" lon=\"").append(end.lng).append("\">\n")
            sb.append("    <name>🛑 End: ").append(escapeXml(end.timestamp ?: "")).append("</name>\n")
            end.alt?.let { sb.append("    <ele>").append(it).append("</ele>\n") }
            sb.append("  </wpt>\n")
        }

        // Full Track
        sb.append("  <trk>\n")
        sb.append("    <name>").append(escapeXml(title)).append("</name>\n")
        sb.append("    <trkseg>\n")

        data.points.forEach { pt ->
            sb.append("      <trkpt lat=\"").append(pt.lat).append("\" lon=\"").append(pt.lng).append("\">\n")
            pt.alt?.let { sb.append("        <ele>").append(it).append("</ele>\n") }
            pt.timestamp?.let { sb.append("        <time>").append(it).append("</time>\n") }
            if (pt.bearing != null || pt.speed != null) {
                sb.append("        <extensions>\n")
                pt.bearing?.let { sb.append("          <course>").append(it).append("</course>\n") }
                pt.speed?.let { sb.append("          <speed>").append(it).append("</speed>\n") }
                sb.append("        </extensions>\n")
            }
            sb.append("      </trkpt>\n")
        }

        sb.append("    </trkseg>\n")
        sb.append("  </trk>\n")
        sb.append("</gpx>\n")

        FileOutputStream(file).use { out ->
            out.write(sb.toString().toByteArray(Charsets.UTF_8))
        }
        return file
    }

    /**
     * Exports JourneyData to KML 2.2 format (opens natively in Google Earth & Google Maps).
     */
    fun exportToKml(context: Context, data: JourneyData, title: String): File {
        val fileName = "Track_${sanitizeFileName(title)}_${sdfFile.format(Date())}.kml"
        val exportDir = File(context.cacheDir, "exports").apply { mkdirs() }
        val file = File(exportDir, fileName)

        val sb = StringBuilder()
        sb.append("""<?xml version="1.0" encoding="UTF-8"?>""").append("\n")
        sb.append("""<kml xmlns="http://www.opengis.net/kml/2.2">""").append("\n")
        sb.append("  <Document>\n")
        sb.append("    <name>").append(escapeXml(title)).append("</name>\n")

        // Style for route line (Deep Blue)
        sb.append("    <Style id=\"routeLine\">\n")
        sb.append("      <LineStyle>\n")
        sb.append("        <color>ffeb6325</color>\n") // AABBGGRR format: Alpha, Blue, Green, Red
        sb.append("        <width>5</width>\n")
        sb.append("      </LineStyle>\n")
        sb.append("    </Style>\n")

        // Start Placemark
        data.points.firstOrNull()?.let { start ->
            sb.append("    <Placemark>\n")
            sb.append("      <name>🏁 Start Point</name>\n")
            sb.append("      <description>Time: ").append(escapeXml(start.timestamp ?: "N/A")).append("</description>\n")
            sb.append("      <Point>\n")
            sb.append("        <coordinates>").append(start.lng).append(",").append(start.lat).append(",").append(start.alt ?: 0.0).append("</coordinates>\n")
            sb.append("      </Point>\n")
            sb.append("    </Placemark>\n")
        }

        // End Placemark
        if (data.points.size > 1) {
            val end = data.points.last()
            sb.append("    <Placemark>\n")
            sb.append("      <name>🛑 End Point</name>\n")
            val distStr = if (data.distanceMeters >= 1000) String.format(Locale.US, "%.2f km", data.distanceMeters / 1000) else String.format(Locale.US, "%.0f m", data.distanceMeters)
            sb.append("      <description>Time: ").append(escapeXml(end.timestamp ?: "N/A")).append("\nDistance: ").append(distStr).append("</description>\n")
            sb.append("      <Point>\n")
            sb.append("        <coordinates>").append(end.lng).append(",").append(end.lat).append(",").append(end.alt ?: 0.0).append("</coordinates>\n")
            sb.append("      </Point>\n")
            sb.append("    </Placemark>\n")
        }

        // Path LineString
        sb.append("    <Placemark>\n")
        sb.append("      <name>GPS Journey Path</name>\n")
        sb.append("      <styleUrl>#routeLine</styleUrl>\n")
        sb.append("      <LineString>\n")
        sb.append("        <extrude>1</extrude>\n")
        sb.append("        <tessellate>1</tessellate>\n")
        sb.append("        <coordinates>\n")

        data.points.forEach { pt ->
            sb.append("          ").append(pt.lng).append(",").append(pt.lat).append(",").append(pt.alt ?: 0.0).append("\n")
        }

        sb.append("        </coordinates>\n")
        sb.append("      </LineString>\n")
        sb.append("    </Placemark>\n")

        sb.append("  </Document>\n")
        sb.append("</kml>\n")

        FileOutputStream(file).use { out ->
            out.write(sb.toString().toByteArray(Charsets.UTF_8))
        }
        return file
    }

    /**
     * Exports JourneyData to GeoJSON FeatureCollection format (ready for QGIS, ArcGIS, Mapbox).
     */
    fun exportToGeoJson(context: Context, data: JourneyData, title: String): File {
        val fileName = "Track_${sanitizeFileName(title)}_${sdfFile.format(Date())}.geojson"
        val exportDir = File(context.cacheDir, "exports").apply { mkdirs() }
        val file = File(exportDir, fileName)

        val coordinatesList = data.points.map { pt ->
            listOfNotNull(pt.lng, pt.lat, pt.alt)
        }

        val features = mutableListOf<Map<String, Any>>()

        // Route LineString feature
        features.add(
            mapOf(
                "type" to "Feature",
                "properties" to mapOf(
                    "name" to title,
                    "distance_meters" to data.distanceMeters,
                    "duration_seconds" to data.durationSeconds,
                    "waypoints_count" to data.points.size,
                    "start_time" to (data.startTime ?: ""),
                    "end_time" to (data.endTime ?: "")
                ),
                "geometry" to mapOf(
                    "type" to "LineString",
                    "coordinates" to coordinatesList
                )
            )
        )

        // Start Point feature
        data.points.firstOrNull()?.let { start ->
            features.add(
                mapOf(
                    "type" to "Feature",
                    "properties" to mapOf(
                        "name" to "Start Point",
                        "time" to (start.timestamp ?: ""),
                        "accuracy" to (start.accuracy ?: 0f)
                    ),
                    "geometry" to mapOf(
                        "type" to "Point",
                        "coordinates" to listOfNotNull(start.lng, start.lat, start.alt)
                    )
                )
            )
        }

        // End Point feature
        if (data.points.size > 1) {
            val end = data.points.last()
            features.add(
                mapOf(
                    "type" to "Feature",
                    "properties" to mapOf(
                        "name" to "End Point",
                        "time" to (end.timestamp ?: ""),
                        "accuracy" to (end.accuracy ?: 0f)
                    ),
                    "geometry" to mapOf(
                        "type" to "Point",
                        "coordinates" to listOfNotNull(end.lng, end.lat, end.alt)
                    )
                )
            )
        }

        val root = mapOf(
            "type" to "FeatureCollection",
            "features" to features
        )

        val json = GsonBuilder().setPrettyPrinting().create().toJson(root)
        FileOutputStream(file).use { out ->
            out.write(json.toByteArray(Charsets.UTF_8))
        }
        return file
    }

    /**
     * Launches the system Sharesheet with the generated track file.
     */
    fun shareTrackFile(context: Context, file: File, mimeType: String, title: String) {
        val uri = FileProvider.getUriForFile(
            context,
            "${context.packageName}.fileprovider",
            file
        )

        val intent = Intent(Intent.ACTION_SEND).apply {
            type = mimeType
            putExtra(Intent.EXTRA_STREAM, uri)
            putExtra(Intent.EXTRA_SUBJECT, title)
            putExtra(Intent.EXTRA_TEXT, "Exported GPS journey track from FieldPlus: ${file.name}")
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
        }

        context.startActivity(Intent.createChooser(intent, "Share GPS Track"))
    }

    private fun escapeXml(str: String): String {
        return str.replace("&", "&amp;")
            .replace("<", "&lt;")
            .replace(">", "&gt;")
            .replace("\"", "&quot;")
            .replace("'", "&apos;")
    }

    private fun sanitizeFileName(name: String): String {
        return name.replace(Regex("[^a-zA-Z0-9_-]"), "_").take(30)
    }
}
