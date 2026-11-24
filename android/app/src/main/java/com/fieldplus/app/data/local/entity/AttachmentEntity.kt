package com.fieldplus.app.data.local.entity

import androidx.room.Entity
import androidx.room.Index
import androidx.room.PrimaryKey

@Entity(
    tableName = "attachments",
    indices = [
        Index(value = ["submissionUuid"]),
        Index(value = ["fieldId"])
    ]
)
data class AttachmentEntity(
    @PrimaryKey(autoGenerate = true) val id: Long = 0,
    val submissionUuid: String,
    val fieldId: String,
    val fileType: String, // "image", "signature", "file"
    val localPath: String,
    val mimeType: String? = null,
    val createdAt: Long = System.currentTimeMillis()
)
