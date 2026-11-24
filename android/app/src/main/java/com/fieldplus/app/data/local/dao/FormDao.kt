package com.fieldplus.app.data.local.dao

import androidx.lifecycle.LiveData
import androidx.room.Dao
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query
import com.fieldplus.app.data.local.entity.FormEntity

@Dao
interface FormDao {
    @Query("SELECT * FROM forms WHERE isActive = 1 ORDER BY title ASC")
    fun getAllActiveForms(): LiveData<List<FormEntity>>

    @Query("SELECT * FROM forms WHERE isActive = 1 ORDER BY title ASC")
    suspend fun getAllActiveFormsDirect(): List<FormEntity>

    @Query("SELECT * FROM forms WHERE id = :formId LIMIT 1")
    suspend fun getFormById(formId: String): FormEntity?

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun insertForms(forms: List<FormEntity>)

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun insertForm(form: FormEntity)

    @Query("DELETE FROM forms WHERE id NOT IN (:activeIds)")
    suspend fun deleteOldForms(activeIds: List<String>)

    @Query("DELETE FROM forms WHERE id = :formId")
    suspend fun deleteFormById(formId: String)

    @Query("DELETE FROM forms")
    suspend fun deleteAllForms()

    @Query("SELECT COUNT(*) FROM forms WHERE isActive = 1")
    fun getActiveFormsCount(): LiveData<Int>
}
