package com.fieldplus.app.ui.widget

import android.content.Context
import android.graphics.*
import android.util.AttributeSet
import android.view.MotionEvent
import android.view.View
import java.io.File
import java.io.FileOutputStream

class SignaturePadView @JvmOverloads constructor(
    context: Context,
    attrs: AttributeSet? = null,
    defStyleAttr: Int = 0
) : View(context, attrs, defStyleAttr) {

    private val path = Path()
    private val paint = Paint().apply {
        isAntiAlias = true
        isDither = true
        color = Color.parseColor("#0F172A")
        style = Paint.Style.STROKE
        strokeJoin = Paint.Join.ROUND
        strokeCap = Paint.Cap.ROUND
        strokeWidth = 6f
    }

    private var canvasBitmap: Bitmap? = null
    private var drawCanvas: Canvas? = null
    private val canvasPaint = Paint(Paint.DITHER_FLAG)

    private var lastX = 0f
    private var lastY = 0f
    private var hasSignature = false

    var onSignatureChanged: (() -> Unit)? = null

    override fun onSizeChanged(w: Int, h: Int, oldw: Int, oldh: Int) {
        super.onSizeChanged(w, h, oldw, oldh)
        if (w > 0 && h > 0) {
            val bitmap = Bitmap.createBitmap(w, h, Bitmap.Config.ARGB_8888)
            val canvas = Canvas(bitmap)
            canvas.drawColor(Color.WHITE)
            canvasBitmap = bitmap
            drawCanvas = canvas
        }
    }

    override fun onDraw(canvas: Canvas) {
        super.onDraw(canvas)
        canvasBitmap?.let {
            canvas.drawBitmap(it, 0f, 0f, canvasPaint)
        }
        canvas.drawPath(path, paint)
    }

    override fun onTouchEvent(event: MotionEvent): Boolean {
        parent.requestDisallowInterceptTouchEvent(true)
        val x = event.x
        val y = event.y

        when (event.action) {
            MotionEvent.ACTION_DOWN -> {
                path.reset()
                path.moveTo(x, y)
                lastX = x
                lastY = y
                hasSignature = true
                invalidate()
            }
            MotionEvent.ACTION_MOVE -> {
                val dx = Math.abs(x - lastX)
                val dy = Math.abs(y - lastY)
                if (dx >= 4f || dy >= 4f) {
                    path.quadTo(lastX, lastY, (x + lastX) / 2, (y + lastY) / 2)
                    lastX = x
                    lastY = y
                    invalidate()
                }
            }
            MotionEvent.ACTION_UP -> {
                path.lineTo(lastX, lastY)
                drawCanvas?.drawPath(path, paint)
                path.reset()
                invalidate()
                onSignatureChanged?.invoke()
            }
        }
        return true
    }

    fun clear() {
        path.reset()
        canvasBitmap?.eraseColor(Color.WHITE)
        hasSignature = false
        invalidate()
        onSignatureChanged?.invoke()
    }

    fun hasSignature(): Boolean = hasSignature

    fun loadFromFile(file: File): Boolean {
        if (!file.exists() || file.length() == 0L) return false
        return try {
            val bmp = BitmapFactory.decodeFile(file.absolutePath) ?: return false
            post {
                val w = width
                val h = height
                if (w > 0 && h > 0) {
                    val targetBitmap = Bitmap.createBitmap(w, h, Bitmap.Config.ARGB_8888)
                    val canvas = Canvas(targetBitmap)
                    canvas.drawColor(Color.WHITE)
                    val srcRect = Rect(0, 0, bmp.width, bmp.height)
                    val destRect = Rect(0, 0, w, h)
                    canvas.drawBitmap(bmp, srcRect, destRect, canvasPaint)
                    canvasBitmap = targetBitmap
                    drawCanvas = canvas
                    hasSignature = true
                    invalidate()
                    onSignatureChanged?.invoke()
                }
            }
            true
        } catch (e: Exception) {
            e.printStackTrace()
            false
        }
    }

    fun saveToFile(file: File): Boolean {
        return try {
            val bitmap = canvasBitmap ?: return false
            FileOutputStream(file).use { out ->
                bitmap.compress(Bitmap.CompressFormat.PNG, 100, out)
            }
            true
        } catch (e: Exception) {
            e.printStackTrace()
            false
        }
    }
}
