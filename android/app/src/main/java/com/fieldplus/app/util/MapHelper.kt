package com.fieldplus.app.util

import android.content.Context
import android.content.Intent
import android.net.Uri
import android.widget.Toast
import androidx.appcompat.app.AlertDialog

object MapHelper {

    fun openInGoogleMaps(
        context: Context,
        latitude: Double,
        longitude: Double,
        label: String? = null,
        pathPoints: List<Pair<Double, Double>>? = null
    ) {
        // If pathPoints has >= 2 points, open navigation/route mode showing full journey path
        if (pathPoints != null && pathPoints.size >= 2) {
            val origin = "${pathPoints.first().first},${pathPoints.first().second}"
            val destination = "${pathPoints.last().first},${pathPoints.last().second}"
            
            // Build intermediate waypoints (Google Maps URL accepts up to 10 waypoints)
            val waypointsStr = if (pathPoints.size > 2) {
                val intermediates = if (pathPoints.size <= 10) {
                    pathPoints.subList(1, pathPoints.size - 1)
                } else {
                    // Sample up to 8 intermediate points evenly
                    val step = (pathPoints.size - 2).toDouble() / 8.0
                    (0 until 8).map { i ->
                        val index = 1 + (i * step).toInt().coerceAtMost(pathPoints.size - 2)
                        pathPoints[index]
                    }
                }
                intermediates.joinToString("|") { "${it.first},${it.second}" }
            } else ""

            val dirUrl = StringBuilder("https://www.google.com/maps/dir/?api=1")
                .append("&origin=").append(origin)
                .append("&destination=").append(destination)
                .append("&travelmode=walking")
            if (waypointsStr.isNotEmpty()) {
                dirUrl.append("&waypoints=").append(Uri.encode(waypointsStr))
            }

            val dirIntent = Intent(Intent.ACTION_VIEW, Uri.parse(dirUrl.toString())).apply {
                setPackage("com.google.android.apps.maps")
            }
            try {
                context.startActivity(dirIntent)
                return
            } catch (_: Exception) {
                // Fallback to web directions or browser
                try {
                    context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(dirUrl.toString())))
                    return
                } catch (e: Exception) {
                    Toast.makeText(context, "Could not open Maps: ${e.message}", Toast.LENGTH_SHORT).show()
                }
            }
            return
        }

        // Single coordinate pin
        val query = if (!label.isNullOrBlank()) {
            "$latitude,$longitude(${Uri.encode(label)})"
        } else {
            "$latitude,$longitude"
        }
        
        // 1. Try Google Maps application directly
        val gmapsIntent = Intent(Intent.ACTION_VIEW, Uri.parse("geo:$latitude,$longitude?q=$query")).apply {
            setPackage("com.google.android.apps.maps")
        }

        try {
            context.startActivity(gmapsIntent)
            return
        } catch (_: Exception) {
            // Not installed or package restricted
        }

        // 2. Try generic geo URI (opens any installed map app)
        val genericGeoIntent = Intent(Intent.ACTION_VIEW, Uri.parse("geo:$latitude,$longitude?q=$query"))
        try {
            context.startActivity(genericGeoIntent)
            return
        } catch (_: Exception) {
            // No native map app handler
        }

        // 3. Fallback to Google Maps Web
        try {
            val webUri = Uri.parse("https://www.google.com/maps?q=$latitude,$longitude")
            context.startActivity(Intent(Intent.ACTION_VIEW, webUri))
        } catch (e: Exception) {
            Toast.makeText(context, "Could not open Google Maps: ${e.message}", Toast.LENGTH_SHORT).show()
        }
    }

    fun openInOpenStreetMap(
        context: Context,
        latitude: Double,
        longitude: Double,
        label: String? = null,
        pathPoints: List<Pair<Double, Double>>? = null
    ) {
        try {
            val osmUri = if (pathPoints != null && pathPoints.size >= 2) {
                // Routing engine on OpenStreetMap (Foot routing)
                val start = "${pathPoints.first().first},${pathPoints.first().second}"
                val end = "${pathPoints.last().first},${pathPoints.last().second}"
                Uri.parse("https://www.openstreetmap.org/directions?engine=fossgis_osrm_foot&route=$start%3B$end")
            } else {
                // OpenStreetMap web viewer with pin marker and zoom level 16
                Uri.parse("https://www.openstreetmap.org/?mlat=$latitude&mlon=$longitude#map=16/$latitude/$longitude")
            }
            val intent = Intent(Intent.ACTION_VIEW, osmUri)
            context.startActivity(intent)
        } catch (e: Exception) {
            Toast.makeText(context, "Could not open OpenStreetMap: ${e.message}", Toast.LENGTH_SHORT).show()
        }
    }

    fun openPreferredMap(
        context: Context,
        latitude: Double,
        longitude: Double,
        label: String? = null,
        pathPoints: List<Pair<Double, Double>>? = null
    ) {
        val prefs = PreferenceManager(context)
        if (prefs.mapProvider == PreferenceManager.MAP_PROVIDER_OSM) {
            openInOpenStreetMap(context, latitude, longitude, label, pathPoints)
        } else {
            openInGoogleMaps(context, latitude, longitude, label, pathPoints)
        }
    }

    fun showMapProviderChooser(
        context: Context,
        latitude: Double,
        longitude: Double,
        label: String? = null,
        title: String = "View Location on Map",
        pathPoints: List<Pair<Double, Double>>? = null
    ) {
        val prefs = PreferenceManager(context)
        val current = prefs.mapProvider
        val hasPath = pathPoints != null && pathPoints.size >= 2
        val pathSuffix = if (hasPath) " (Full Track: ${pathPoints.size} pts)" else ""
        val options = arrayOf(
            "🗺️ Google Maps$pathSuffix" + if (current == PreferenceManager.MAP_PROVIDER_GOOGLE) " (Current Default)" else "",
            "🌍 OpenStreetMap (OSM)$pathSuffix" + if (current == PreferenceManager.MAP_PROVIDER_OSM) " (Current Default)" else ""
        )

        AlertDialog.Builder(context)
            .setTitle(if (hasPath) "View GPS Journey Track" else title)
            .setItems(options) { _, which ->
                when (which) {
                    0 -> openInGoogleMaps(context, latitude, longitude, label, pathPoints)
                    1 -> openInOpenStreetMap(context, latitude, longitude, label, pathPoints)
                }
            }
            .setNeutralButton("Settings") { _, _ ->
                val intent = Intent(context, com.fieldplus.app.ui.settings.SettingsActivity::class.java)
                context.startActivity(intent)
            }
            .setNegativeButton("Cancel", null)
            .show()
    }
}
