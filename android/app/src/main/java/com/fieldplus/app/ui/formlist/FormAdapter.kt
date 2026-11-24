package com.fieldplus.app.ui.formlist

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import com.fieldplus.app.data.local.entity.FormEntity
import com.fieldplus.app.data.model.FormSchema
import com.fieldplus.app.databinding.ItemFormBinding
import com.google.gson.Gson

class FormAdapter(
    private val onFormSelected: (FormEntity) -> Unit,
    private val onDeleteForm: (FormEntity) -> Unit
) : ListAdapter<FormEntity, FormAdapter.FormViewHolder>(DiffCallback) {

    private val gson = Gson()

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): FormViewHolder {
        val binding = ItemFormBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return FormViewHolder(binding)
    }

    override fun onBindViewHolder(holder: FormViewHolder, position: Int) {
        holder.bind(getItem(position))
    }

    inner class FormViewHolder(private val binding: ItemFormBinding) : RecyclerView.ViewHolder(binding.root) {
        fun bind(item: FormEntity) {
            binding.tvFormTitle.text = item.title
            binding.tvFormDescription.text = item.description ?: "Field visit assessment"
            binding.tvCategory.text = item.category ?: "General"
            binding.tvVersion.text = "v${item.version}"

            try {
                val schema = gson.fromJson(item.schemaJson, FormSchema::class.java)
                val sections = schema.sections.size
                var fields = 0
                schema.sections.forEach { fields += it.fields.size }
                binding.tvFieldCount.text = "$sections Sections • $fields Controls"
            } catch (e: Exception) {
                binding.tvFieldCount.text = "Form Ready"
            }

            binding.btnStartForm.setOnClickListener { onFormSelected(item) }
            binding.btnDeleteForm.setOnClickListener { onDeleteForm(item) }
            binding.root.setOnClickListener { onFormSelected(item) }
        }
    }

    companion object {
        private val DiffCallback = object : DiffUtil.ItemCallback<FormEntity>() {
            override fun areItemsTheSame(oldItem: FormEntity, newItem: FormEntity): Boolean =
                oldItem.id == newItem.id

            override fun areContentsTheSame(oldItem: FormEntity, newItem: FormEntity): Boolean =
                oldItem == newItem
        }
    }
}
