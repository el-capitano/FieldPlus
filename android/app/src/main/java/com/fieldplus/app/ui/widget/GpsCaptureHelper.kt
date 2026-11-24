package com.fieldplus.app.ui.widget

import android.Manifest
import android.annotation.SuppressLint
import android.content.Context
import android.content.pm.PackageManager
import android.location.Location
import android.location.LocationListener
import android.location.LocationManager
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import androidx.core.content.ContextCompat
import com.fieldplus.app.data.model.GpsCoordinate
import com.google.android.gms.location.LocationCallback
import com.google.android.gms.location.LocationRequest
import com.google.android.gms.location.LocationResult
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import java.util.Locale

class GpsCaptureHelper(private val context: Context) {

    private val fusedLocationClient = LocationServices.getFusedLocationProviderClient(context)
    private val locationManager = context.getSystemService(Context.LOCATION_SERVICE) as? LocationManager

    private var activeCallback: LocationCallback? = null
    private var activeGpsListener: LocationListener? = null
    private var activeNetworkListener: LocationListener? = null
    private var timeoutHandler: Handler? = null
    private var timeoutRunnable: Runnable? = null
    private var isCapturing = false

    var currentCandidate: GpsCoordinate? = null
        private set

    fun hasLocationPermission(): Boolean {
        val fine = ContextCompat.checkSelfPermission(
            context,
            Manifest.permission.ACCESS_FINE_LOCATION
        ) == PackageManager.PERMISSION_GRANTED
        val coarse = ContextCompat.checkSelfPermission(
            context,
            Manifest.permission.ACCESS_COARSE_LOCATION
        ) == PackageManager.PERMISSION_GRANTED
        return fine || coarse
    }

    fun isGpsProviderEnabled(): Boolean {
        val lm = locationManager ?: return false
        return lm.isProviderEnabled(LocationManager.GPS_PROVIDER) ||
                lm.isProviderEnabled(LocationManager.NETWORK_PROVIDER)
    }

    fun lockCurrentFix(): GpsCoordinate? {
        val coord = currentCandidate
        if (coord != null) {
            stopActiveCapture()
        }
        return coord
    }

    @SuppressLint("MissingPermission")
    fun captureRealLocation(
        targetAccuracyMeters: Float = 20.0f,
        timeoutSeconds: Int = 10,
        onProgress: (status: String, candidate: GpsCoordinate?) -> Unit,
        onSuccess: (GpsCoordinate) -> Unit,
        onError: (String) -> Unit
    ) {
        if (!hasLocationPermission()) {
            onError("Location permission is required to capture real GPS coordinates.")
            return
        }

        if (!isGpsProviderEnabled()) {
            onError("Location services (GPS) are disabled on this device. Please turn on GPS in system settings.")
            return
        }

        stopActiveCapture()
        isCapturing = true
        currentCandidate = null

        var bestLocation: Location? = null
        val startTime = System.currentTimeMillis()

        fun toCoord(loc: Location): GpsCoordinate {
            return GpsCoordinate(
                latitude = Math.round(loc.latitude * 1000000.0) / 1000000.0,
                longitude = Math.round(loc.longitude * 1000000.0) / 1000000.0,
                accuracy = if (loc.hasAccuracy()) loc.accuracy else null,
                altitude = if (loc.hasAltitude()) loc.altitude else null,
                timestamp = loc.time
            )
        }

        fun evaluateLocation(location: Location?, isInitialCache: Boolean = false) {
            if (!isCapturing || location == null) return

            val acc = if (location.hasAccuracy()) location.accuracy else 50f
            val bestAcc = if (bestLocation != null && bestLocation!!.hasAccuracy()) bestLocation!!.accuracy else Float.MAX_VALUE

            if (bestLocation == null || acc <= bestAcc) {
                bestLocation = location
            }

            val currentAcc = bestLocation?.accuracy ?: acc
            val bestCoord = toCoord(bestLocation ?: location)
            currentCandidate = bestCoord
            val elapsedSec = (System.currentTimeMillis() - startTime) / 1000

            val providerName = (bestLocation ?: location).provider ?: "GNSS"
            onProgress(
                String.format(Locale.US, "GPS: ±%.1fm (%s)", currentAcc, providerName.uppercase(Locale.US)),
                bestCoord
            )

            // 1. If this is an existing cache fix with good accuracy (<= 25m), lock immediately
            if (isInitialCache && currentAcc <= 25.0f) {
                stopActiveCapture()
                onSuccess(bestCoord)
                return
            }

            // 2. High accuracy (<= targetAccuracyMeters, e.g. 20m): lock immediately!
            if (currentAcc <= targetAccuracyMeters) {
                stopActiveCapture()
                onSuccess(bestCoord)
                return
            }

            // 3. Fast field fix: after 1 second if accuracy <= 35m
            if (elapsedSec >= 1 && currentAcc <= 35.0f) {
                stopActiveCapture()
                onSuccess(bestCoord)
                return
            }

            // 4. Usable fix: after 3 seconds if accuracy <= 65m
            if (elapsedSec >= 3 && currentAcc <= 65.0f) {
                stopActiveCapture()
                onSuccess(bestCoord)
                return
            }

            // 5. Fallback fix: after 5 seconds if accuracy <= 120m
            if (elapsedSec >= 5 && currentAcc <= 120.0f) {
                stopActiveCapture()
                onSuccess(bestCoord)
                return
            }
        }

        onProgress("Detecting GPS satellites & network position...", null)

        // 1. Check all last known locations immediately for zero-delay candidate (<50ms)
        try {
            fusedLocationClient.lastLocation.addOnSuccessListener { lastLoc ->
                if (lastLoc != null) {
                    evaluateLocation(lastLoc, isInitialCache = true)
                }
            }
        } catch (_: Exception) {}

        try {
            val lm = locationManager
            if (lm != null) {
                val lastGps = if (lm.isProviderEnabled(LocationManager.GPS_PROVIDER)) {
                    lm.getLastKnownLocation(LocationManager.GPS_PROVIDER)
                } else null
                val lastNet = if (lm.isProviderEnabled(LocationManager.NETWORK_PROVIDER)) {
                    lm.getLastKnownLocation(LocationManager.NETWORK_PROVIDER)
                } else null
                val lastPassive = try {
                    lm.getLastKnownLocation(LocationManager.PASSIVE_PROVIDER)
                } catch (_: Exception) { null }

                listOfNotNull(lastGps, lastNet, lastPassive).forEach { cached ->
                    evaluateLocation(cached, isInitialCache = true)
                }
            }
        } catch (_: Exception) {}

        // 2. Request fast one-shot fused location (Wi-Fi + Cell + GPS)
        try {
            val tokenSource = com.google.android.gms.tasks.CancellationTokenSource()
            fusedLocationClient.getCurrentLocation(
                Priority.PRIORITY_HIGH_ACCURACY,
                tokenSource.token
            ).addOnSuccessListener { loc ->
                if (loc != null) {
                    evaluateLocation(loc)
                }
            }
        } catch (_: Exception) {}

        // 3. Request active continuous updates via FusedLocationProviderClient (every 500ms)
        try {
            val locationRequest = LocationRequest.Builder(Priority.PRIORITY_HIGH_ACCURACY, 500L).apply {
                setMinUpdateIntervalMillis(250L)
                setMaxUpdateDelayMillis(1000L)
                setMinUpdateDistanceMeters(0f)
            }.build()

            val callback = object : LocationCallback() {
                override fun onLocationResult(result: LocationResult) {
                    for (loc in result.locations) {
                        evaluateLocation(loc)
                    }
                }
            }
            activeCallback = callback
            fusedLocationClient.requestLocationUpdates(locationRequest, callback, Looper.getMainLooper())
        } catch (_: Exception) {}

        // 4. Simultaneously listen on native Android LocationManager (GPS_PROVIDER & NETWORK_PROVIDER)
        try {
            val lm = locationManager
            if (lm != null) {
                if (lm.isProviderEnabled(LocationManager.GPS_PROVIDER)) {
                    val gpsListener = object : LocationListener {
                        override fun onLocationChanged(location: Location) {
                            evaluateLocation(location)
                        }
                        override fun onProviderDisabled(provider: String) {}
                        override fun onProviderEnabled(provider: String) {}
                        @Deprecated("Deprecated in Java")
                        override fun onStatusChanged(provider: String?, status: Int, extras: Bundle?) {}
                    }
                    activeGpsListener = gpsListener
                    lm.requestLocationUpdates(LocationManager.GPS_PROVIDER, 500L, 0f, gpsListener, Looper.getMainLooper())
                }

                if (lm.isProviderEnabled(LocationManager.NETWORK_PROVIDER)) {
                    val netListener = object : LocationListener {
                        override fun onLocationChanged(location: Location) {
                            evaluateLocation(location)
                        }
                        override fun onProviderDisabled(provider: String) {}
                        override fun onProviderEnabled(provider: String) {}
                        @Deprecated("Deprecated in Java")
                        override fun onStatusChanged(provider: String?, status: Int, extras: Bundle?) {}
                    }
                    activeNetworkListener = netListener
                    lm.requestLocationUpdates(LocationManager.NETWORK_PROVIDER, 500L, 0f, netListener, Looper.getMainLooper())
                }
            }
        } catch (_: Exception) {}

        // 5. Timeout safety handler
        timeoutHandler = Handler(Looper.getMainLooper())
        timeoutRunnable = Runnable {
            if (isCapturing) {
                stopActiveCapture()
                if (bestLocation != null) {
                    onSuccess(toCoord(bestLocation!!))
                } else {
                    onError("GPS acquisition timed out (${timeoutSeconds}s). Unable to acquire satellite lock. Please move to an outdoor area with clear sky view or enable Google Location Accuracy in system settings.")
                }
            }
        }
        timeoutHandler?.postDelayed(timeoutRunnable!!, timeoutSeconds * 1000L)
    }

    fun stopActiveCapture() {
        isCapturing = false
        timeoutRunnable?.let { timeoutHandler?.removeCallbacks(it) }
        timeoutRunnable = null
        timeoutHandler = null

        activeCallback?.let {
            try {
                fusedLocationClient.removeLocationUpdates(it)
            } catch (_: Exception) {}
        }
        activeCallback = null

        try {
            val lm = locationManager
            activeGpsListener?.let { lm?.removeUpdates(it) }
            activeNetworkListener?.let { lm?.removeUpdates(it) }
        } catch (_: Exception) {}
        activeGpsListener = null
        activeNetworkListener = null
    }

    private var journeyCallback: LocationCallback? = null
    private var journeyGpsListener: LocationListener? = null
    private var isJourneyTracking = false
    private var lastRecordedTimeMs: Long = 0L

    @SuppressLint("MissingPermission")
    fun startContinuousTracking(
        intervalMs: Long = 1500L,
        onLocationReceived: (GpsCoordinate) -> Unit
    ) {
        if (!hasLocationPermission()) return
        stopContinuousTracking()
        isJourneyTracking = true
        lastRecordedTimeMs = 0L

        fun processLocation(loc: Location) {
            if (!isJourneyTracking) return

            // Accuracy filter: reject inaccurate drift points (> 35m)
            if (loc.hasAccuracy() && loc.accuracy > 35.0f) {
                return
            }

            // Rate limiter: avoid flooding with duplicate fixes faster than 800ms
            val now = System.currentTimeMillis()
            if (now - lastRecordedTimeMs < 800L) {
                return
            }
            lastRecordedTimeMs = now

            val coord = GpsCoordinate(
                latitude = Math.round(loc.latitude * 1000000.0) / 1000000.0,
                longitude = Math.round(loc.longitude * 1000000.0) / 1000000.0,
                accuracy = if (loc.hasAccuracy()) loc.accuracy else null,
                altitude = if (loc.hasAltitude()) loc.altitude else null,
                timestamp = loc.time,
                bearing = if (loc.hasBearing() && loc.bearing != 0.0f) loc.bearing else null,
                speed = if (loc.hasSpeed()) loc.speed else null
            )
            onLocationReceived(coord)
        }

        // 1. Google Play Services Fused Location Client (1500ms interval, 1m distance)
        try {
            val locationRequest = LocationRequest.Builder(Priority.PRIORITY_HIGH_ACCURACY, intervalMs).apply {
                setMinUpdateIntervalMillis(1000L)
                setMaxUpdateDelayMillis(1500L)
                setMinUpdateDistanceMeters(1.0f) // Detect turns and 1-meter movements immediately
            }.build()

            val callback = object : LocationCallback() {
                override fun onLocationResult(result: LocationResult) {
                    for (loc in result.locations) {
                        processLocation(loc)
                    }
                }
            }
            journeyCallback = callback
            fusedLocationClient.requestLocationUpdates(locationRequest, callback, Looper.getMainLooper())
        } catch (_: Exception) {}

        // 2. Direct hardware GPS Provider listener in parallel for raw GNSS satellite fixes
        try {
            val lm = locationManager
            if (lm != null && lm.isProviderEnabled(LocationManager.GPS_PROVIDER)) {
                val listener = object : LocationListener {
                    override fun onLocationChanged(location: Location) {
                        processLocation(location)
                    }
                    override fun onProviderDisabled(provider: String) {}
                    override fun onProviderEnabled(provider: String) {}
                    @Deprecated("Deprecated in Java")
                    override fun onStatusChanged(provider: String?, status: Int, extras: Bundle?) {}
                }
                journeyGpsListener = listener
                lm.requestLocationUpdates(LocationManager.GPS_PROVIDER, intervalMs, 1.0f, listener, Looper.getMainLooper())
            }
        } catch (_: Exception) {}
    }

    fun stopContinuousTracking() {
        isJourneyTracking = false
        journeyCallback?.let {
            try {
                fusedLocationClient.removeLocationUpdates(it)
            } catch (_: Exception) {}
        }
        journeyCallback = null

        journeyGpsListener?.let {
            try {
                locationManager?.removeUpdates(it)
            } catch (_: Exception) {}
        }
        journeyGpsListener = null
    }
}
