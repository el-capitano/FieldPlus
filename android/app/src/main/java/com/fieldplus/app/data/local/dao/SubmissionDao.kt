package com.fieldplus.app.data.local.dao

import androidx.lifecycle.LiveData
import androidx.room.*
import com.fieldplus.app.data.local.entity.SubmissionEntity

@Dao
interface SubmissionDao {
    @Query("SELECT * FROM submissions ORDER BY updatedAt DESC")
    fun getAllSubmissions(): LiveData<List<SubmissionEntity>>

    @Query("SELECT * FROM submissions WHERE status = :status ORDER BY updatedAt DESC")
    fun getSubmissionsByStatus(status: String): LiveData<List<SubmissionEntity>>

    @Query("SELECT * FROM submissions WHERE status IN ('PENDING_SYNC', 'ERROR') ORDER BY createdAt ASC")
    suspend fun getPendingSyncSubmissions(): List<SubmissionEntity>

    @Query("SELECT * FROM submissions WHERE submissionUuid = :uuid LIMIT 1")
    suspend fun getSubmissionByUuid(uuid: String): SubmissionEntity?

    @Query("SELECT * FROM submissions WHERE id = :id LIMIT 1")
    suspend fun getSubmissionById(id: Long): SubmissionEntity?

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun insertSubmission(submission: SubmissionEntity): Long

    @Update
    suspend fun updateSubmission(submission: SubmissionEntity)

    @Query("UPDATE submissions SET status = :newStatus, errorMessage = :errorMsg, updatedAt = :time WHERE submissionUuid = :uuid")
    suspend fun updateStatus(uuid: String, newStatus: String, errorMsg: String? = null, time: Long = System.currentTimeMillis())

    @Query("DELETE FROM submissions WHERE submissionUuid = :uuid")
    suspend fun deleteSubmission(uuid: String)

    @Query("DELETE FROM submissions WHERE status = 'SYNCED'")
    suspend fun clearSyncedSubmissions(): Int

    @Query("DELETE FROM submissions")
    suspend fun clearAllSubmissions(): Int

    @Query("DELETE FROM submissions WHERE formId = :formId")
    suspend fun clearSubmissionsForForm(formId: String): Int

    @Query("SELECT COUNT(*) FROM submissions WHERE status IN ('PENDING_SYNC', 'ERROR')")
    fun getPendingCount(): LiveData<Int>

    @Query("SELECT COUNT(*) FROM submissions WHERE status IN ('PENDING_SYNC', 'ERROR')")
    suspend fun getPendingCountDirect(): Int

    @Query("SELECT COUNT(*) FROM submissions WHERE status = 'DRAFT'")
    fun getDraftsCount(): LiveData<Int>
}
