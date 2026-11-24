package com.fieldplus.app

import com.fieldplus.app.data.local.entity.SubmissionEntity
import com.fieldplus.app.data.model.FieldType
import com.fieldplus.app.data.model.FormField
import com.fieldplus.app.data.model.FormSchema
import com.fieldplus.app.data.model.FormSection
import com.fieldplus.app.data.model.GpsCoordinate
import com.google.gson.Gson
import org.junit.Assert.*
import org.junit.Test

class FormSchemaTest {

    private val gson = Gson()

    @Test
    fun testFormSchemaSerializationAndDeserialization() {
        val schema = FormSchema(
            id = "form_audit_01",
            title = "Facility Inspection Audit",
            description = "Test description",
            category = "Engineering",
            version = 1,
            sections = listOf(
                FormSection(
                    id = "sec_1",
                    title = "Site Information",
                    description = "General details",
                    fields = listOf(
                        FormField(id = "site_name", type = FieldType.TEXT, label = "Site Name", required = true),
                        FormField(id = "site_gps", type = FieldType.GPS, label = "Site GPS Location", required = true),
                        FormField(id = "inspection_date", type = FieldType.DATE, label = "Date", required = true),
                        FormField(id = "exterior_photo", type = FieldType.FILE, label = "Photo", required = false, fileType = "image"),
                        FormField(id = "inspector_sig", type = FieldType.SIGNATURE, label = "Signature", required = true)
                    )
                )
            )
        )

        val json = gson.toJson(schema)
        assertNotNull(json)
        assertTrue(json.contains("form_audit_01"))
        assertTrue(json.contains("site_gps"))
        assertTrue(json.contains("inspector_sig"))

        val deserialized = gson.fromJson(json, FormSchema::class.java)
        assertEquals("form_audit_01", deserialized.id)
        assertEquals(1, deserialized.sections.size)
        assertEquals(5, deserialized.sections[0].fields.size)
        assertEquals(FieldType.GPS, deserialized.sections[0].fields[1].type)
        assertEquals(FieldType.SIGNATURE, deserialized.sections[0].fields[4].type)
    }

    @Test
    fun testGpsCoordinateModel() {
        val coord = GpsCoordinate(
            latitude = 24.7136,
            longitude = 46.6753,
            accuracy = 3.5f,
            altitude = 612.0
        )
        val json = gson.toJson(coord)
        val parsed = gson.fromJson(json, GpsCoordinate::class.java)

        assertEquals(24.7136, parsed.latitude, 0.0001)
        assertEquals(46.6753, parsed.longitude, 0.0001)
        assertEquals(3.5f, parsed.accuracy!!, 0.01f)
    }

    @Test
    fun testSubmissionStatusTransitions() {
        var sub = SubmissionEntity(
            submissionUuid = "sub_test_123",
            formId = "form_audit_01",
            formTitle = "Audit",
            submitterName = "John",
            status = SubmissionEntity.STATUS_DRAFT,
            answersJson = "{}"
        )

        assertEquals(SubmissionEntity.STATUS_DRAFT, sub.status)

        // Finalize
        sub = sub.copy(status = SubmissionEntity.STATUS_PENDING_SYNC)
        assertEquals(SubmissionEntity.STATUS_PENDING_SYNC, sub.status)

        // Synced
        sub = sub.copy(status = SubmissionEntity.STATUS_SYNCED)
        assertEquals(SubmissionEntity.STATUS_SYNCED, sub.status)
    }
}
