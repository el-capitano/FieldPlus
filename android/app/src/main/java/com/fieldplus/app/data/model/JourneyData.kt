package com.fieldplus.app.data.model

import com.google.gson.annotations.SerializedName

data class JourneyPoint(
    @SerializedName("lat") val lat: Double,
    @SerializedName("lng") val lng: Double,
    @SerializedName("alt") val alt: Double? = null,
    @SerializedName("accuracy") val accuracy: Float? = null,
    @SerializedName("timestamp") val timestamp: String? = null,
    @SerializedName("bearing") val bearing: Float? = null,                 // Bearing in degrees (0..359.9)
    @SerializedName("speed") val speed: Float? = null,                     // Speed in m/s
    @SerializedName("direction") val direction: String? = null,             // "North (N)", "North-East (NE)", etc.
    @SerializedName("arrow") val arrow: String? = null,                     // "⬆", "↗", "➡", "↘", "⬇", "↙", "⬅", "↖"
    @SerializedName("distance_from_prev") val distanceFromPrev: Double? = null // Distance from previous point in meters
) {
    val distance_from_prev: Double? get() = distanceFromPrev
}

data class JourneyData(
    @SerializedName("journey_path") val points: List<JourneyPoint> = emptyList(),
    @SerializedName("start_time") val startTime: String? = null,
    @SerializedName("end_time") val endTime: String? = null,
    @SerializedName("duration_seconds") val durationSeconds: Long = 0,
    @SerializedName("distance_meters") val distanceMeters: Double = 0.0,
    @SerializedName("status") val status: String = "COMPLETED" // RECORDING, PAUSED, COMPLETED
)

object JourneyGeoUtils {

    /**
     * Calculates the initial geodesic bearing from point 1 (lat1, lon1) to point 2 (lat2, lon2)
     * in degrees from 0° to 359.9° relative to true north.
     */
    fun calculateBearing(lat1: Double, lon1: Double, lat2: Double, lon2: Double): Float {
        val lat1Rad = Math.toRadians(lat1)
        val lat2Rad = Math.toRadians(lat2)
        val dLonRad = Math.toRadians(lon2 - lon1)

        val y = Math.sin(dLonRad) * Math.cos(lat2Rad)
        val x = Math.cos(lat1Rad) * Math.sin(lat2Rad) -
                Math.sin(lat1Rad) * Math.cos(lat2Rad) * Math.cos(dLonRad)
        var brng = Math.toDegrees(Math.atan2(y, x)).toFloat()
        brng = (brng + 360f) % 360f
        return Math.round(brng * 10f) / 10f
    }

    /**
     * Converts a bearing angle in degrees into an 8-point compass cardinal direction string.
     */
    fun getDirectionLabel(bearing: Float): String {
        val b = (bearing % 360f + 360f) % 360f
        return when {
            b >= 337.5 || b < 22.5 -> "North (N)"
            b >= 22.5 && b < 67.5 -> "North-East (NE)"
            b >= 67.5 && b < 112.5 -> "East (E)"
            b >= 112.5 && b < 157.5 -> "South-East (SE)"
            b >= 157.5 && b < 202.5 -> "South (S)"
            b >= 202.5 && b < 247.5 -> "South-West (SW)"
            b >= 247.5 && b < 292.5 -> "West (W)"
            else -> "North-West (NW)"
        }
    }

    /**
     * Returns an arrow emoji matching the travel heading.
     */
    fun getDirectionArrow(bearing: Float): String {
        val b = (bearing % 360f + 360f) % 360f
        return when {
            b >= 337.5 || b < 22.5 -> "⬆"
            b >= 22.5 && b < 67.5 -> "↗"
            b >= 67.5 && b < 112.5 -> "➡"
            b >= 112.5 && b < 157.5 -> "↘"
            b >= 157.5 && b < 202.5 -> "⬇"
            b >= 202.5 && b < 247.5 -> "↙"
            b >= 247.5 && b < 292.5 -> "⬅"
            else -> "↖"
        }
    }
}
