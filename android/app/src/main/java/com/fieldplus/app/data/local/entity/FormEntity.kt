package com.fieldplus.app.data.local.entity

import androidx.room.Entity
import androidx.room.PrimaryKey

@Entity(tableName = "forms")
data class FormEntity(
    @PrimaryKey val id: String,
    val title: String,
    val description: String?,
    val category: String?,
    val version: Int,
    val isActive: Boolean,
    val schemaJson: String,
    val cachedAt: Long = System.currentTimeMillis()
)
