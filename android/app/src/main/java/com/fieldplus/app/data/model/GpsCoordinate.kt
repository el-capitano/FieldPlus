package com.fieldplus.app.data.model

import com.google.gson.annotations.SerializedName

data class GpsCoordinate(
    @SerializedName("latitude") val latitude: Double,
    @SerializedName("longitude") val longitude: Double,
    @SerializedName("accuracy") val accuracy: Float? = null,
    @SerializedName("altitude") val altitude: Double? = null,
    @SerializedName("timestamp") val timestamp: Long = System.currentTimeMillis(),
    @SerializedName("bearing") val bearing: Float? = null,
    @SerializedName("speed") val speed: Float? = null
)
