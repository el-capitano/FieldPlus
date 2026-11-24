package com.fieldplus.app.data.model

import com.google.gson.annotations.SerializedName

data class FormsListResponse(
    @SerializedName("status") val status: String,
    @SerializedName("count") val count: Int,
    @SerializedName("data") val data: List<FormDto>
)

data class FormDto(
    @SerializedName("id") val id: String,
    @SerializedName("title") val title: String,
    @SerializedName("description") val description: String?,
    @SerializedName("category") val category: String?,
    @SerializedName("version") val version: Int,
    @SerializedName("is_active") val isActive: Boolean,
    @SerializedName("field_count") val fieldCount: Int?,
    @SerializedName("updated_at") val updatedAt: String?,
    @SerializedName("schema") val schema: FormSchema
)

data class SubmissionSyncResponse(
    @SerializedName("status") val status: String,
    @SerializedName("submission_uuid") val submissionUuid: String?,
    @SerializedName("message") val message: String?,
    @SerializedName("attachments_saved") val attachmentsSaved: Int?,
    @SerializedName("server_timestamp") val serverTimestamp: String?
)

data class HealthResponse(
    @SerializedName("status") val status: String,
    @SerializedName("service") val service: String?,
    @SerializedName("version") val version: String?,
    @SerializedName("server_time") val serverTime: String?,
    @SerializedName("active_forms") val activeForms: Int?,
    @SerializedName("total_submissions") val totalSubmissions: Int?
)
