package com.fieldplus.app.service

import android.annotation.SuppressLint
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.location.Location
import android.location.LocationListener
import android.location.LocationManager
import android.os.Build
import android.os.Bundle
import android.os.IBinder
import android.os.Looper
import android.os.PowerManager
import androidx.core.app.NotificationCompat
import com.fieldplus.app.R
import com.fieldplus.app.data.model.JourneyGeoUtils
import com.fieldplus.app.data.model.JourneyPoint
import com.fieldplus.app.ui.formfill.DynamicFormActivity
import com.google.android.gms.location.FusedLocationProviderClient
import com.google.android.gms.location.LocationCallback
import com.google.android.gms.location.LocationRequest
import com.google.android.gms.location.LocationResult
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

class JourneyTrackingService : Service() {

    private lateinit var fusedLocationClient: FusedLocationProviderClient
    private var locationManager: LocationManager? = null
    private var wakeLock: PowerManager.WakeLock? = null

    private var locationCallback: LocationCallback? = null
    private var gpsListener: LocationListener? = null

    private val sdfIso = SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ss", Locale.US)
    private var lastRecordedLoc: Location? = null
    private var lastRecordedTimeMs: Long = 0L

    override fun onCreate() {
        super.onCreate()
        fusedLocationClient = LocationServices.getFusedLocationProviderClient(this)
        locationManager = getSystemService(Context.LOCATION_SERVICE) as? LocationManager

        createNotificationChannel()

        val powerManager = getSystemService(Context.POWER_SERVICE) as? PowerManager
        wakeLock = powerManager?.newWakeLock(
            PowerManager.PARTIAL_WAKE_LOCK,
            "FieldPlus:GpsJourneyTrackingWakeLock"
        )?.apply {
            setReferenceCounted(false)
            acquire(4 * 60 * 60 * 1000L) // Safety cap 4 hours
        }
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        val action = intent?.action ?: ACTION_START

        when (action) {
            ACTION_STOP -> {
                stopTrackingSession()
                stopForeground(STOP_FOREGROUND_REMOVE)
                stopSelf()
                return START_NOT_STICKY
            }
            ACTION_PAUSE -> {
                pauseTrackingSession()
                updateNotification("Tracking Paused • $totalPointsRecorded waypoints logged")
                return START_STICKY
            }
            ACTION_START, ACTION_RESUME -> {
                val fieldId = intent?.getStringExtra(EXTRA_FIELD_ID) ?: activeFieldId ?: "journey_field"
                val formTitle = intent?.getStringExtra(EXTRA_FORM_TITLE) ?: activeFormTitle ?: "Field Inspection"
                activeFieldId = fieldId
                activeFormTitle = formTitle
                isTrackingActive = true

                val initialNotification = buildNotification("Starting GPS journey tracking...")
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                    startForeground(
                        NOTIFICATION_ID,
                        initialNotification,
                        ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION
                    )
                } else {
                    startForeground(NOTIFICATION_ID, initialNotification)
                }

                startLocationUpdates()
            }
        }

        return START_STICKY
    }

    @SuppressLint("MissingPermission")
    private fun startLocationUpdates() {
        stopLocationUpdates()

        fun handleLocation(loc: Location) {
            if (!isTrackingActive) return

            // Accuracy filter: discard low-precision fixes (> 35m) to avoid zig-zags
            if (loc.hasAccuracy() && loc.accuracy > 35.0f) {
                return
            }

            // Rate limiter: max 1 point per 800ms
            val now = System.currentTimeMillis()
            if (now - lastRecordedTimeMs < 800L) {
                return
            }

            // Stationary filter: discard jitter when standing still (< 2.5m and speed < 0.5 m/s)
            var distFromPrev = 0.0
            val last = lastRecordedLoc
            if (last != null) {
                val results = FloatArray(1)
                Location.distanceBetween(last.latitude, last.longitude, loc.latitude, loc.longitude, results)
                distFromPrev = results[0].toDouble()

                val isStationary = distFromPrev < 2.5 && (!loc.hasSpeed() || loc.speed < 0.5f)
                if (isStationary && (now - lastRecordedTimeMs < 20000L)) {
                    // Ignore jitter if under 2.5m and under 20s
                    return
                }
            }

            lastRecordedLoc = loc
            lastRecordedTimeMs = now
            totalPointsRecorded++
            totalDistanceMeters += distFromPrev

            // Calculate bearing & direction
            val bearing = if (loc.hasBearing() && loc.bearing != 0.0f) {
                loc.bearing
            } else if (last != null && distFromPrev >= 2.0) {
                JourneyGeoUtils.calculateBearing(last.latitude, last.longitude, loc.latitude, loc.longitude)
            } else null

            val dirLabel = bearing?.let { JourneyGeoUtils.getDirectionLabel(it) }
            val arrow = bearing?.let { JourneyGeoUtils.getDirectionArrow(it) }

            val point = JourneyPoint(
                lat = Math.round(loc.latitude * 1000000.0) / 1000000.0,
                lng = Math.round(loc.longitude * 1000000.0) / 1000000.0,
                alt = if (loc.hasAltitude()) Math.round(loc.altitude * 10.0) / 10.0 else null,
                accuracy = if (loc.hasAccuracy()) loc.accuracy else null,
                timestamp = sdfIso.format(Date(loc.time)),
                bearing = bearing,
                speed = if (loc.hasSpeed()) loc.speed else null,
                direction = dirLabel,
                arrow = arrow,
                distanceFromPrev = Math.round(distFromPrev * 10.0) / 10.0
            )

            currentHeadingDirection = dirLabel
            currentHeadingArrow = arrow

            val distFormatted = if (totalDistanceMeters >= 1000.0) {
                String.format(Locale.US, "%.2f km", totalDistanceMeters / 1000.0)
            } else {
                String.format(Locale.US, "%.0f m", totalDistanceMeters)
            }

            val headingDesc = if (arrow != null && dirLabel != null) " • $arrow $dirLabel" else ""
            updateNotification("$totalPointsRecorded points • $distFormatted$headingDesc")

            // Dispatch to active in-app listener
            activeFieldId?.let { fId ->
                onPointRecorded?.invoke(fId, point, totalDistanceMeters)
            }
        }

        // 1. Google Play Services Fused Location Provider (1500ms interval, 1m distance)
        try {
            val locationRequest = LocationRequest.Builder(Priority.PRIORITY_HIGH_ACCURACY, 1500L).apply {
                setMinUpdateIntervalMillis(1000L)
                setMaxUpdateDelayMillis(1500L)
                setMinUpdateDistanceMeters(1.0f)
            }.build()

            val callback = object : LocationCallback() {
                override fun onLocationResult(result: LocationResult) {
                    for (loc in result.locations) {
                        handleLocation(loc)
                    }
                }
            }
            locationCallback = callback
            fusedLocationClient.requestLocationUpdates(locationRequest, callback, Looper.getMainLooper())
        } catch (_: Exception) {}

        // 2. Native GNSS Hardware Listener for immediate satellite fixes
        try {
            val lm = locationManager
            if (lm != null && lm.isProviderEnabled(LocationManager.GPS_PROVIDER)) {
                val listener = object : LocationListener {
                    override fun onLocationChanged(location: Location) {
                        handleLocation(location)
                    }
                    override fun onProviderDisabled(provider: String) {}
                    override fun onProviderEnabled(provider: String) {}
                    @Deprecated("Deprecated in Java")
                    override fun onStatusChanged(provider: String?, status: Int, extras: Bundle?) {}
                }
                gpsListener = listener
                lm.requestLocationUpdates(LocationManager.GPS_PROVIDER, 1500L, 1.0f, listener, Looper.getMainLooper())
            }
        } catch (_: Exception) {}
    }

    private fun pauseTrackingSession() {
        isTrackingActive = false
        stopLocationUpdates()
    }

    private fun stopTrackingSession() {
        isTrackingActive = false
        stopLocationUpdates()
        wakeLock?.let {
            if (it.isHeld) it.release()
        }
        wakeLock = null
    }

    private fun stopLocationUpdates() {
        locationCallback?.let {
            try {
                fusedLocationClient.removeLocationUpdates(it)
            } catch (_: Exception) {}
        }
        locationCallback = null

        gpsListener?.let {
            try {
                locationManager?.removeUpdates(it)
            } catch (_: Exception) {}
        }
        gpsListener = null
    }

    private fun createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel(
                CHANNEL_ID,
                "GPS Journey Tracking",
                NotificationManager.IMPORTANCE_LOW
            ).apply {
                description = "Shows live GPS track recording status in background"
                setShowBadge(false)
            }
            val nm = getSystemService(NotificationManager::class.java)
            nm?.createNotificationChannel(channel)
        }
    }

    private fun buildNotification(statusText: String): Notification {
        val intent = Intent(this, DynamicFormActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP
        }
        val pendingIntent = PendingIntent.getActivity(
            this,
            0,
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        return NotificationCompat.Builder(this, CHANNEL_ID)
            .setContentTitle("Recording GPS Journey Track")
            .setContentText(statusText)
            .setSmallIcon(R.mipmap.ic_launcher)
            .setContentIntent(pendingIntent)
            .setOngoing(true)
            .setCategory(NotificationCompat.CATEGORY_SERVICE)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .build()
    }

    private fun updateNotification(statusText: String) {
        val nm = getSystemService(Context.NOTIFICATION_SERVICE) as? NotificationManager
        nm?.notify(NOTIFICATION_ID, buildNotification(statusText))
    }

    override fun onDestroy() {
        stopTrackingSession()
        super.onDestroy()
    }

    override fun onBind(intent: Intent?): IBinder? = null

    companion object {
        const val CHANNEL_ID = "channel_gps_journey_tracking"
        const val NOTIFICATION_ID = 4004

        const val ACTION_START = "com.fieldplus.app.service.ACTION_START"
        const val ACTION_PAUSE = "com.fieldplus.app.service.ACTION_PAUSE"
        const val ACTION_RESUME = "com.fieldplus.app.service.ACTION_RESUME"
        const val ACTION_STOP = "com.fieldplus.app.service.ACTION_STOP"

        const val EXTRA_FIELD_ID = "extra_field_id"
        const val EXTRA_FORM_TITLE = "extra_form_title"

        var isTrackingActive: Boolean = false
            private set
        var activeFieldId: String? = null
            private set
        var activeFormTitle: String? = null
            private set
        var totalPointsRecorded: Int = 0
            private set
        var totalDistanceMeters: Double = 0.0
            private set
        var currentHeadingDirection: String? = null
            private set
        var currentHeadingArrow: String? = null
            private set

        var onPointRecorded: ((fieldId: String, point: JourneyPoint, totalDistance: Double) -> Unit)? = null

        fun startTracking(context: Context, fieldId: String, formTitle: String) {
            totalPointsRecorded = 0
            totalDistanceMeters = 0.0
            val intent = Intent(context, JourneyTrackingService::class.java).apply {
                action = ACTION_START
                putExtra(EXTRA_FIELD_ID, fieldId)
                putExtra(EXTRA_FORM_TITLE, formTitle)
            }
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                context.startForegroundService(intent)
            } else {
                context.startService(intent)
            }
        }

        fun resumeTracking(context: Context) {
            val intent = Intent(context, JourneyTrackingService::class.java).apply {
                action = ACTION_RESUME
            }
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                context.startForegroundService(intent)
            } else {
                context.startService(intent)
            }
        }

        fun pauseTracking(context: Context) {
            val intent = Intent(context, JourneyTrackingService::class.java).apply {
                action = ACTION_PAUSE
            }
            context.startService(intent)
        }

        fun stopTracking(context: Context) {
            val intent = Intent(context, JourneyTrackingService::class.java).apply {
                action = ACTION_STOP
            }
            context.startService(intent)
            onPointRecorded = null
            activeFieldId = null
            activeFormTitle = null
            isTrackingActive = false
        }
    }
}
