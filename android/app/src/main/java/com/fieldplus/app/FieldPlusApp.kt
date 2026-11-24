package com.fieldplus.app

import android.app.Application
import com.fieldplus.app.data.local.AppDatabase
import com.fieldplus.app.worker.SyncWorker

class FieldPlusApp : Application() {

    override fun onCreate() {
        super.onCreate()
        // Initialize Room DB eagerly
        AppDatabase.getInstance(this)

        // Schedule periodic background sync when device has network connectivity
        SyncWorker.schedulePeriodicSync(this)
    }
}

