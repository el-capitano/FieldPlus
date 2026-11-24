package com.fieldplus.app.data.local.dao

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query
import com.fieldplus.app.data.local.entity.AttachmentEntity

@Dao
interface AttachmentDao {
    @Query("SELECT * FROM attachments WHERE submissionUuid = :uuid")
    suspend fun getAttachmentsForSubmission(uuid: String): List<AttachmentEntity>

    @Query("SELECT * FROM attachments WHERE submissionUuid = :uuid AND fieldId = :fieldId LIMIT 1")
    suspend fun getAttachmentForField(uuid: String, fieldId: String): AttachmentEntity?

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun insertAttachment(attachment: AttachmentEntity): Long

    @Query("DELETE FROM attachments WHERE submissionUuid = :uuid")
    suspend fun deleteAttachmentsForSubmission(uuid: String)
}
