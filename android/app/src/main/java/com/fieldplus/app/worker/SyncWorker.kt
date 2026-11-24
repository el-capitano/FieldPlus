package com.fieldplus.app.worker

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.os.Build
import android.util.Log
import androidx.core.app.NotificationCompat
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.ForegroundInfo
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkInfo
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import androidx.work.workDataOf
import com.fieldplus.app.R
import com.fieldplus.app.data.model.SyncProgressUpdate
import com.fieldplus.app.data.repository.SubmissionRepository
import com.fieldplus.app.ui.submissions.SubmissionsActivity
import com.fieldplus.app.util.PreferenceManager
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.asSharedFlow
import java.util.concurrent.TimeUnit

class SyncWorker(
    appContext: Context,
    workerParams: WorkerParameters
) : CoroutineWorker(appContext, workerParams) {

    private val notificationManager =
        applicationContext.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager

    override suspend fun doWork(): Result {
        Log.d(TAG, "SyncWorker started execution...")
        val prefs = PreferenceManager(applicationContext)

        if (!prefs.isBackgroundSyncEnabled) {
            Log.d(TAG, "Background sync is disabled in settings. Skipping.")
            return Result.success()
        }

        val repository = SubmissionRepository(applicationContext)
        val pendingCount = repository.getPendingCountDirect()

        if (pendingCount <= 0) {
            Log.d(TAG, "No pending submissions to sync. Skipping foreground notification.")
            notificationManager.cancel(NOTIFICATION_ID)
            return Result.success()
        }

        createNotificationChannel()

        // Set foreground info so Android keeps work alive and displays the notification
        try {
            val initialForegroundInfo = createForegroundInfo("Syncing $pendingCount pending submission(s)...", 0, 100, true)
            setForeground(initialForegroundInfo)
        } catch (e: Exception) {
            Log.w(TAG, "Unable to run as foreground service: ${e.message}")
        }

        return try {
            val result = repository.syncAllPending(
                shouldCancel = { isStopped }
            ) { progress ->
                // Broadcast in-app
                _progressFlow.tryEmit(progress)

                // Update WorkManager progress for LiveData observers
                setProgressAsync(
                    workDataOf(
                        KEY_SUBMISSION_UUID to progress.currentSubmissionUuid,
                        KEY_FORM_TITLE to progress.currentFormTitle,
                        KEY_PERCENT to progress.percent,
                        KEY_STATUS_TEXT to progress.statusText,
                        KEY_IS_COMPLETE to progress.isComplete,
                        KEY_CURRENT_INDEX to progress.currentItemIndex,
                        KEY_TOTAL_ITEMS to progress.totalItems
                    )
                )

                // Update System Notification
                if (!progress.isComplete) {
                    val notif = createNotification(
                        title = "Syncing Field Surveys (${progress.currentItemIndex}/${progress.totalItems})",
                        text = progress.statusText,
                        progress = progress.percent,
                        max = 100,
                        indeterminate = false
                    )
                    notificationManager.notify(NOTIFICATION_ID, notif)
                }
            }

            Log.d(TAG, "Sync result: ${result.message}")

            if (result.synced > 0 || result.failed > 0) {
                // Complete notification
                val completeNotif = NotificationCompat.Builder(applicationContext, CHANNEL_ID)
                    .setSmallIcon(R.drawable.ic_sync)
                    .setContentTitle(if (result.failed == 0) "FieldPlus Sync Complete" else "FieldPlus Sync Completed with Warnings")
                    .setContentText(result.message)
                    .setPriority(NotificationCompat.PRIORITY_LOW)
                    .setAutoCancel(true)
                    .setContentIntent(createPendingIntent())
                    .build()

                notificationManager.notify(NOTIFICATION_ID, completeNotif)
            } else {
                notificationManager.cancel(NOTIFICATION_ID)
            }

            if (result.failed > 0 && result.synced == 0) {
                Result.retry()
            } else {
                Result.success()
            }
        } catch (e: Exception) {
            Log.e(TAG, "Error executing background sync", e)
            val errorNotif = NotificationCompat.Builder(applicationContext, CHANNEL_ID)
                .setSmallIcon(R.drawable.ic_sync)
                .setContentTitle("FieldPlus Sync Error")
                .setContentText("Sync paused: ${e.message}. Will retry.")
                .setPriority(NotificationCompat.PRIORITY_LOW)
                .setAutoCancel(true)
                .setContentIntent(createPendingIntent())
                .build()
            notificationManager.notify(NOTIFICATION_ID, errorNotif)
            Result.retry()
        }
    }

    private fun createForegroundInfo(text: String, progress: Int, max: Int, indeterminate: Boolean): ForegroundInfo {
        val notification = createNotification("FieldPlus Background Sync", text, progress, max, indeterminate)
        return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            ForegroundInfo(NOTIFICATION_ID, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_DATA_SYNC)
        } else {
            ForegroundInfo(NOTIFICATION_ID, notification)
        }
    }

    private fun createNotification(
        title: String,
        text: String,
        progress: Int,
        max: Int,
        indeterminate: Boolean
    ) = NotificationCompat.Builder(applicationContext, CHANNEL_ID)
        .setSmallIcon(R.drawable.ic_sync)
        .setContentTitle(title)
        .setContentText(text)
        .setOngoing(true)
        .setOnlyAlertOnce(true)
        .setProgress(max, progress, indeterminate)
        .setPriority(NotificationCompat.PRIORITY_LOW)
        .setContentIntent(createPendingIntent())
        .build()

    private fun createPendingIntent(): PendingIntent {
        val intent = Intent(applicationContext, SubmissionsActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP
        }
        return PendingIntent.getActivity(
            applicationContext,
            0,
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
    }

    private fun createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel(
                CHANNEL_ID,
                "FieldPlus Data Sync",
                NotificationManager.IMPORTANCE_DEFAULT
            ).apply {
                description = "Shows real-time progress of surveys, photos, and videos synchronization"
                setShowBadge(true)
            }
            notificationManager.createNotificationChannel(channel)
        }
    }

    companion object {
        private const val TAG = "SyncWorker"
        const val CHANNEL_ID = "fieldplus_sync_channel"
        const val NOTIFICATION_ID = 2001
        private const val PERIODIC_WORK_NAME = "fieldplus_periodic_sync"
        const val ONE_TIME_WORK_NAME = "fieldplus_immediate_sync"

        const val KEY_SUBMISSION_UUID = "key_submission_uuid"
        const val KEY_FORM_TITLE = "key_form_title"
        const val KEY_PERCENT = "key_percent"
        const val KEY_STATUS_TEXT = "key_status_text"
        const val KEY_IS_COMPLETE = "key_is_complete"
        const val KEY_CURRENT_INDEX = "key_current_index"
        const val KEY_TOTAL_ITEMS = "key_total_items"

        private val _progressFlow = MutableSharedFlow<SyncProgressUpdate>(extraBufferCapacity = 64)
        val progressFlow: SharedFlow<SyncProgressUpdate> = _progressFlow.asSharedFlow()

        fun schedulePeriodicSync(context: Context) {
            val prefs = PreferenceManager(context)
            if (!prefs.isBackgroundSyncEnabled) {
                WorkManager.getInstance(context).cancelUniqueWork(PERIODIC_WORK_NAME)
                Log.d(TAG, "Background sync disabled. Periodic work cancelled.")
                return
            }

            val networkType = if (prefs.isWifiOnlySync) NetworkType.UNMETERED else NetworkType.CONNECTED

            val constraints = Constraints.Builder()
                .setRequiredNetworkType(networkType)
                .build()

            val syncRequest = PeriodicWorkRequestBuilder<SyncWorker>(15, TimeUnit.MINUTES)
                .setConstraints(constraints)
                .build()

            WorkManager.getInstance(context).enqueueUniquePeriodicWork(
                PERIODIC_WORK_NAME,
                ExistingPeriodicWorkPolicy.UPDATE,
                syncRequest
            )
            Log.d(TAG, "Periodic sync scheduled every 15 minutes (networkType=$networkType).")
        }

        fun enqueueImmediateSync(context: Context) {
            val prefs = PreferenceManager(context)
            val networkType = if (prefs.isWifiOnlySync) NetworkType.UNMETERED else NetworkType.CONNECTED

            val constraints = Constraints.Builder()
                .setRequiredNetworkType(networkType)
                .build()

            val syncRequest = OneTimeWorkRequestBuilder<SyncWorker>()
                .setConstraints(constraints)
                .build()

            WorkManager.getInstance(context).enqueueUniqueWork(
                ONE_TIME_WORK_NAME,
                ExistingWorkPolicy.REPLACE,
                syncRequest
            )
            Log.d(TAG, "Immediate sync work enqueued.")
        }

        fun cancelImmediateSync(context: Context) {
            WorkManager.getInstance(context).cancelUniqueWork(ONE_TIME_WORK_NAME)
            val notificationManager = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
            notificationManager.cancel(NOTIFICATION_ID)
            _progressFlow.tryEmit(
                SyncProgressUpdate(
                    currentSubmissionUuid = "",
                    currentFormTitle = "",
                    currentItemIndex = 0,
                    totalItems = 0,
                    percent = 0,
                    statusText = "Sync cancelled by user",
                    isComplete = true
                )
            )
            Log.d(TAG, "Immediate sync cancelled by user.")
        }
    }
}
