package com.fieldplus.app.data.repository

import android.content.Context
import androidx.lifecycle.LiveData
import com.fieldplus.app.data.local.AppDatabase
import com.fieldplus.app.data.local.entity.FormEntity
import com.fieldplus.app.data.remote.ApiClient
import com.google.gson.Gson
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

class FormRepository(context: Context) {

    private val db = AppDatabase.getInstance(context)
    private val formDao = db.formDao()
    private val apiClient = ApiClient(context)
    private val gson = Gson()

    fun getAllForms(): LiveData<List<FormEntity>> = formDao.getAllActiveForms()

    suspend fun getFormById(formId: String): FormEntity? = withContext(Dispatchers.IO) {
        formDao.getFormById(formId)
    }

    suspend fun refreshForms(): Result<Int> = withContext(Dispatchers.IO) {
        try {
            val api = apiClient.getService()
            val response = api.getForms()

            if (response.isSuccessful && response.body() != null) {
                val formsList = response.body()!!.data
                val entities = formsList.map { dto ->
                    FormEntity(
                        id = dto.id,
                        title = dto.title,
                        description = dto.description,
                        category = dto.category,
                        version = dto.version,
                        isActive = dto.isActive,
                        schemaJson = gson.toJson(dto.schema),
                        cachedAt = System.currentTimeMillis()
                    )
                }

                val activeIds = entities.map { it.id }
                if (activeIds.isNotEmpty()) {
                    // Automatically remove forms that no longer exist on the server
                    formDao.deleteOldForms(activeIds)
                } else {
                    formDao.deleteAllForms()
                }

                formDao.insertForms(entities)
                Result.success(entities.size)
            } else if (response.code() == 401) {
                val seeded = seedDefaultTemplatesIfEmpty()
                if (seeded > 0) Result.success(seeded) else Result.failure(Exception("Authentication required (HTTP 401). Please scan QR code in Settings."))
            } else {
                val seeded = seedDefaultTemplatesIfEmpty()
                if (seeded > 0) Result.success(seeded) else Result.failure(Exception("Failed to fetch forms: HTTP ${response.code()}"))
            }
        } catch (e: Exception) {
            val seeded = seedDefaultTemplatesIfEmpty()
            if (seeded > 0) {
                Result.success(seeded)
            } else {
                Result.failure(e)
            }
        }
    }

    suspend fun seedDefaultTemplatesIfEmpty(): Int = withContext(Dispatchers.IO) {
        val existing = formDao.getAllActiveFormsDirect()
        if (existing.isNotEmpty()) return@withContext existing.size

        val sampleSchema = com.fieldplus.app.data.model.FormSchema(
            id = "tpl_enterprise_site_audit",
            title = "Enterprise Site & Asset Inspection",
            description = "Full-scope field inspection covering asset barcodes, GPS continuous journey route, photo evidence, voice memos, and digital signature sign-off.",
            category = "Audits & Assets",
            version = 1,
            sections = listOf(
                com.fieldplus.app.data.model.FormSection(
                    id = "sec_asset_id",
                    title = "Asset Identification",
                    description = "Site and asset verification",
                    fields = listOf(
                        com.fieldplus.app.data.model.FormField(
                            id = "f_facility_name",
                            type = com.fieldplus.app.data.model.FieldType.TEXT,
                            label = "Facility / Location Name",
                            required = true,
                            placeholder = "e.g. North Substation Unit 4"
                        ),
                        com.fieldplus.app.data.model.FormField(
                            id = "f_asset_barcode",
                            type = com.fieldplus.app.data.model.FieldType.BARCODE,
                            label = "Asset Barcode / QR Code",
                            required = true,
                            help = "Scan tag directly with Google Code Scanner or generate sample tag"
                        ),
                        com.fieldplus.app.data.model.FormField(
                            id = "f_inspection_date",
                            type = com.fieldplus.app.data.model.FieldType.DATE,
                            label = "Inspection Date",
                            required = true
                        )
                    )
                ),
                com.fieldplus.app.data.model.FormSection(
                    id = "sec_gps_journey",
                    title = "GPS Route & Geo-Tracking",
                    description = "Continuous inspection route recorder",
                    fields = listOf(
                        com.fieldplus.app.data.model.FormField(
                            id = "f_journey_track",
                            type = com.fieldplus.app.data.model.FieldType.JOURNEY_GPS,
                            label = "Field Inspection Route Track",
                            required = false,
                            help = "Records your journey path with direction arrows, timestamps, and exportable GPX/KML/GeoJSON"
                        )
                    )
                ),
                com.fieldplus.app.data.model.FormSection(
                    id = "sec_multimedia_evidence",
                    title = "Multimedia Evidence & Audio Notes",
                    description = "Audio notes and visual evidence",
                    fields = listOf(
                        com.fieldplus.app.data.model.FormField(
                            id = "f_voice_memo",
                            type = com.fieldplus.app.data.model.FieldType.AUDIO,
                            label = "Inspector Voice Memo / Audio Notes",
                            required = false,
                            help = "Record hands-free audio observations and noise measurements"
                        ),
                        com.fieldplus.app.data.model.FormField(
                            id = "f_site_photos",
                            type = com.fieldplus.app.data.model.FieldType.MULTIPLE_PHOTOS,
                            label = "High-Res Photo Evidence Gallery",
                            required = false,
                            help = "Capture photos of equipment, gauges, and conditions"
                        ),
                        com.fieldplus.app.data.model.FormField(
                            id = "f_walkthrough_video",
                            type = com.fieldplus.app.data.model.FieldType.VIDEO,
                            label = "Inspection Walkthrough Video",
                            required = false,
                            help = "Record walkthrough video clip (< 25 MB)"
                        )
                    )
                ),
                com.fieldplus.app.data.model.FormSection(
                    id = "sec_compliance",
                    title = "Compliance & Sign-off",
                    description = "Assessment and inspector endorsement",
                    fields = listOf(
                        com.fieldplus.app.data.model.FormField(
                            id = "f_status_dropdown",
                            type = com.fieldplus.app.data.model.FieldType.DROPDOWN,
                            label = "Operational Status",
                            required = true,
                            options = listOf("Operational / Pass", "Requires Corrective Maintenance", "Critical Safety Hazard / Shut Down")
                        ),
                        com.fieldplus.app.data.model.FormField(
                            id = "f_notes",
                            type = com.fieldplus.app.data.model.FieldType.TEXTAREA,
                            label = "Inspector Findings & Notes",
                            required = false
                        ),
                        com.fieldplus.app.data.model.FormField(
                            id = "f_inspector_signature",
                            type = com.fieldplus.app.data.model.FieldType.SIGNATURE,
                            label = "Inspector Digital Signature",
                            required = true,
                            help = "Sign to certify this inspection audit"
                        )
                    )
                )
            )
        )

        val defaultEntity = FormEntity(
            id = sampleSchema.id,
            title = sampleSchema.title,
            description = sampleSchema.description,
            category = sampleSchema.category,
            version = 1,
            isActive = true,
            schemaJson = gson.toJson(sampleSchema),
            cachedAt = System.currentTimeMillis()
        )

        formDao.insertForm(defaultEntity)
        1
    }

    suspend fun deleteForm(formId: String) = withContext(Dispatchers.IO) {
        formDao.deleteFormById(formId)
    }
}
