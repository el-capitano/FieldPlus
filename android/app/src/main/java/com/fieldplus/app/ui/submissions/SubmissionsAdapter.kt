package com.fieldplus.app.ui.submissions

import android.content.res.ColorStateList
import android.graphics.Color
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import com.fieldplus.app.R
import com.fieldplus.app.data.local.entity.SubmissionEntity
import com.fieldplus.app.databinding.ItemSubmissionBinding
import java.text.SimpleDateFormat
import java.util.*

class SubmissionsAdapter(
    private val onEditClick: (SubmissionEntity) -> Unit,
    private val onDeleteClick: (SubmissionEntity) -> Unit
) : ListAdapter<SubmissionEntity, SubmissionsAdapter.SubViewHolder>(DiffCallback) {

    private val dateFormat = SimpleDateFormat("yyyy-MM-dd HH:mm", Locale.US)
    private val activeProgressMap = mutableMapOf<String, com.fieldplus.app.data.model.SyncProgressUpdate>()

    fun updateProgress(update: com.fieldplus.app.data.model.SyncProgressUpdate) {
        if (update.isComplete || update.currentSubmissionUuid.isBlank()) {
            activeProgressMap.clear()
            notifyDataSetChanged()
            return
        }
        activeProgressMap[update.currentSubmissionUuid] = update
        val index = currentList.indexOfFirst { it.submissionUuid == update.currentSubmissionUuid }
        if (index != -1) {
            notifyItemChanged(index, update)
        }
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): SubViewHolder {
        val binding = ItemSubmissionBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return SubViewHolder(binding)
    }

    override fun onBindViewHolder(holder: SubViewHolder, position: Int) {
        holder.bind(getItem(position))
    }

    inner class SubViewHolder(private val binding: ItemSubmissionBinding) : RecyclerView.ViewHolder(binding.root) {
        fun bind(item: SubmissionEntity) {
            binding.tvSubFormTitle.text = item.formTitle
            binding.tvSubUuid.text = item.submissionUuid
            binding.tvSubDate.text = dateFormat.format(Date(item.updatedAt))
            binding.tvSubStatus.text = item.status

            // Style status badge
            when (item.status) {
                SubmissionEntity.STATUS_SYNCED -> {
                    binding.tvSubStatus.backgroundTintList = ColorStateList.valueOf(Color.parseColor("#DCFCE7"))
                    binding.tvSubStatus.setTextColor(Color.parseColor("#15803D"))
                }
                SubmissionEntity.STATUS_PENDING_SYNC -> {
                    binding.tvSubStatus.backgroundTintList = ColorStateList.valueOf(Color.parseColor("#FEF3C7"))
                    binding.tvSubStatus.setTextColor(Color.parseColor("#B45309"))
                }
                SubmissionEntity.STATUS_DRAFT -> {
                    binding.tvSubStatus.backgroundTintList = ColorStateList.valueOf(Color.parseColor("#E0E7FF"))
                    binding.tvSubStatus.setTextColor(Color.parseColor("#4338CA"))
                }
                SubmissionEntity.STATUS_ERROR -> {
                    binding.tvSubStatus.backgroundTintList = ColorStateList.valueOf(Color.parseColor("#FEE2E2"))
                    binding.tvSubStatus.setTextColor(Color.parseColor("#B91C1C"))
                }
            }

            // Media attachments badge
            val mediaSummary = extractMediaSummary(item.answersJson)
            if (mediaSummary != null) {
                binding.tvSubMediaBadge.text = mediaSummary
                binding.tvSubMediaBadge.visibility = View.VISIBLE
            } else {
                binding.tvSubMediaBadge.visibility = View.GONE
            }

            // GPS display
            if (item.latitude != null && item.longitude != null) {
                binding.tvSubGps.text = String.format(Locale.US, "📍 %.4f, %.4f", item.latitude, item.longitude)
                binding.tvSubGps.visibility = View.VISIBLE
            } else {
                binding.tvSubGps.text = "No GPS Fix"
            }

            if (!item.errorMessage.isNullOrBlank()) {
                binding.tvSubError.text = item.errorMessage
                binding.tvSubError.visibility = View.VISIBLE
            } else {
                binding.tvSubError.visibility = View.GONE
            }

            // Live Sync Progress Animation & Indicator
            val activeProgress = activeProgressMap[item.submissionUuid]
            if (activeProgress != null && !activeProgress.isComplete) {
                binding.layoutSyncProgress.visibility = View.VISIBLE
                binding.progressSync.setProgress(activeProgress.percent, true)
                binding.tvSyncProgressPercent.text = "${activeProgress.percent}%"
                binding.tvSyncProgressStatus.text = activeProgress.statusText
                binding.tvSubStatus.text = "SYNCING..."
                binding.tvSubStatus.backgroundTintList = ColorStateList.valueOf(Color.parseColor("#DBEAFE"))
                binding.tvSubStatus.setTextColor(Color.parseColor("#1D4ED8"))
                binding.btnEditResume.isEnabled = false
                binding.btnDeleteSub.isEnabled = false
            } else {
                binding.layoutSyncProgress.visibility = View.GONE
                binding.btnEditResume.isEnabled = true
                binding.btnDeleteSub.isEnabled = true
            }

            binding.btnEditResume.text = when (item.status) {
                SubmissionEntity.STATUS_DRAFT -> "Resume Draft"
                SubmissionEntity.STATUS_SYNCED -> "View Details"
                else -> "Review / Edit"
            }
            binding.btnEditResume.setOnClickListener { onEditClick(item) }
            binding.btnDeleteSub.setOnClickListener { onDeleteClick(item) }
        }

        private fun extractMediaSummary(answersJson: String): String? {
            return try {
                val gson = com.google.gson.Gson()
                val type = object : com.google.gson.reflect.TypeToken<Map<String, Any>>() {}.type
                val map: Map<String, Any>? = gson.fromJson(answersJson, type)
                if (map.isNullOrEmpty()) return null

                var photoCount = 0
                var videoCount = 0
                var sigCount = 0

                map.values.forEach { v ->
                    when (v) {
                        is Map<*, *> -> {
                            val typeStr = v["type"] as? String
                            val path = v["path"] as? String ?: v["url"] as? String ?: ""
                            when {
                                typeStr == "video" || path.endsWith(".mp4", ignoreCase = true) -> videoCount++
                                typeStr == "signature" -> sigCount++
                                typeStr == "image" || path.endsWith(".jpg", ignoreCase = true) || path.endsWith(".png", ignoreCase = true) -> photoCount++
                            }
                        }
                        is List<*> -> {
                            v.forEach { item ->
                                if (item is Map<*, *>) {
                                    val typeStr = item["type"] as? String
                                    val path = item["path"] as? String ?: item["url"] as? String ?: ""
                                    when {
                                        typeStr == "video" || path.endsWith(".mp4", ignoreCase = true) -> videoCount++
                                        typeStr == "image" || path.endsWith(".jpg", ignoreCase = true) || path.endsWith(".png", ignoreCase = true) -> photoCount++
                                    }
                                }
                            }
                        }
                    }
                }

                val parts = mutableListOf<String>()
                if (photoCount > 0) parts.add("📷 $photoCount Photo${if (photoCount > 1) "s" else ""}")
                if (videoCount > 0) parts.add("🎥 $videoCount Video${if (videoCount > 1) "s" else ""}")
                if (sigCount > 0) parts.add("✍️ Signed")

                if (parts.isNotEmpty()) parts.joinToString(" • ") else null
            } catch (_: Exception) {
                null
            }
        }
    }

    companion object {
        private val DiffCallback = object : DiffUtil.ItemCallback<SubmissionEntity>() {
            override fun areItemsTheSame(oldItem: SubmissionEntity, newItem: SubmissionEntity): Boolean =
                oldItem.submissionUuid == newItem.submissionUuid

            override fun areContentsTheSame(oldItem: SubmissionEntity, newItem: SubmissionEntity): Boolean =
                oldItem == newItem
        }
    }
}
