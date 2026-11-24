package com.fieldplus.app.data.local

import android.content.Context
import androidx.room.Database
import androidx.room.Room
import androidx.room.RoomDatabase
import com.fieldplus.app.data.local.dao.AttachmentDao
import com.fieldplus.app.data.local.dao.FormDao
import com.fieldplus.app.data.local.dao.SubmissionDao
import com.fieldplus.app.data.local.entity.AttachmentEntity
import com.fieldplus.app.data.local.entity.FormEntity
import com.fieldplus.app.data.local.entity.SubmissionEntity

@Database(
    entities = [FormEntity::class, SubmissionEntity::class, AttachmentEntity::class],
    version = 2,
    exportSchema = false
)
abstract class AppDatabase : RoomDatabase() {
    abstract fun formDao(): FormDao
    abstract fun submissionDao(): SubmissionDao
    abstract fun attachmentDao(): AttachmentDao

    companion object {
        @Volatile
        private var INSTANCE: AppDatabase? = null

        fun getInstance(context: Context): AppDatabase {
            return INSTANCE ?: synchronized(this) {
                val instance = Room.databaseBuilder(
                    context.applicationContext,
                    AppDatabase::class.java,
                    "fieldplus_offline.db"
                )
                    .fallbackToDestructiveMigration()
                    .build()
                INSTANCE = instance
                instance
            }
        }
    }
}
