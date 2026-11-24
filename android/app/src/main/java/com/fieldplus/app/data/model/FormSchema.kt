package com.fieldplus.app.data.model

import com.google.gson.annotations.SerializedName

data class FormSchema(
    @SerializedName("id") val id: String,
    @SerializedName("title") val title: String,
    @SerializedName("description") val description: String? = null,
    @SerializedName("category") val category: String? = "General",
    @SerializedName("version") val version: Int = 1,
    @SerializedName("sections") val sections: List<FormSection> = emptyList()
)

data class FormSection(
    @SerializedName("id") val id: String,
    @SerializedName("title") val title: String,
    @SerializedName("description") val description: String? = null,
    @SerializedName("fields") val fields: List<FormField> = emptyList()
)

data class FormField(
    @SerializedName("id") val id: String,
    @SerializedName("type") val type: String,
    @SerializedName("label") val label: String,
    @SerializedName("placeholder") val placeholder: String? = null,
    @SerializedName("required") val required: Boolean = false,
    @SerializedName("help") val help: String? = null,
    @SerializedName("options") val options: List<String>? = null,
    @SerializedName("default") val defaultValue: Any? = null,
    @SerializedName("file_type") val fileType: String? = null,
    @SerializedName("depends_on") val dependsOn: FieldDependency? = null,
    @SerializedName("min") val min: Double? = null,
    @SerializedName("max") val max: Double? = null,
    @SerializedName("pattern") val pattern: String? = null
)

data class FieldDependency(
    @SerializedName("field") val fieldId: String,
    @SerializedName("operator") val operator: String = "==", // "==", "!=", "in", "contains", "not_empty"
    @SerializedName("value") val value: Any? = null
)

object FieldType {
    const val TEXT = "text"
    const val TEXTAREA = "textarea"
    const val NUMBER = "number"
    const val DATE = "date"
    const val TIME = "time"
    const val GPS = "gps"
    const val JOURNEY_GPS = "journey_gps"
    const val FILE = "file"
    const val MULTIPLE_PHOTOS = "multiple_photos"
    const val VIDEO = "video"
    const val AUDIO = "audio"
    const val SIGNATURE = "signature"
    const val DROPDOWN = "dropdown"
    const val RADIO = "radio"
    const val CHECKBOX = "checkbox"
    const val SWITCH = "switch"
    const val BARCODE = "barcode"
}
