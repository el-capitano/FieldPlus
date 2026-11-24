package com.fieldplus.app.data.remote

import com.fieldplus.app.data.model.FormsListResponse
import com.fieldplus.app.data.model.HealthResponse
import com.fieldplus.app.data.model.SubmissionSyncResponse
import okhttp3.MultipartBody
import okhttp3.RequestBody
import retrofit2.Response
import retrofit2.http.*

interface FieldPlusApiService {

    @GET("health.php")
    suspend fun checkHealth(): Response<HealthResponse>

    @GET("forms.php")
    suspend fun getForms(): Response<FormsListResponse>

    @Multipart
    @POST("submissions.php")
    suspend fun submitForm(
        @Part("submission_uuid") submissionUuid: RequestBody,
        @Part("form_id") formId: RequestBody,
        @Part("device_id") deviceId: RequestBody,
        @Part("submitter_name") submitterName: RequestBody?,
        @Part("submitted_at") submittedAt: RequestBody,
        @Part("data") dataJson: RequestBody,
        @Part files: List<MultipartBody.Part>
    ): Response<SubmissionSyncResponse>
}
