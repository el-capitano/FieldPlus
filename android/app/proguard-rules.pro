# FieldPlus ProGuard / R8 Optimization & Obfuscation Rules

# Kotlin & General Attributes
-keepattributes *Annotation*,Signature,InnerClasses,EnclosingMethod,SourceFile,LineNumberTable
-dontwarn kotlin.**
-dontwarn kotlinx.coroutines.**
-keepclassmembers class kotlinx.coroutines.** { *; }

# Google Gson & Serialized Models
-keepclassmembers class * {
    @com.google.gson.annotations.SerializedName <fields>;
}
-keep class com.google.gson.** { *; }
-keep class com.google.gson.reflect.TypeToken { *; }
-keep class * extends com.google.gson.reflect.TypeToken
-keep class com.fieldplus.app.data.model.** { *; }

# Room Database Entities & DAOs
-keep class androidx.room.** { *; }
-keep class * extends androidx.room.RoomDatabase
-dontwarn androidx.room.paging.**
-keep class com.fieldplus.app.data.local.entity.** { *; }
-keep interface com.fieldplus.app.data.local.dao.** { *; }
-keep class * implements com.fieldplus.app.data.local.dao.** { *; }
-keep class com.fieldplus.app.data.local.AppDatabase { *; }
-keepclassmembers class * {
    @androidx.room.* <methods>;
}

# Retrofit & OkHttp
-dontwarn okhttp3.**
-dontwarn okio.**
-dontwarn retrofit2.**
-keep class retrofit2.** { *; }
-keepclasseswithmembers class * {
    @retrofit2.http.* <methods>;
}
-keep interface com.fieldplus.app.data.remote.** { *; }

# WorkManager
-keep class androidx.work.** { *; }
-keep class * extends androidx.work.ListenableWorker {
    public <init>(android.content.Context, androidx.work.WorkerParameters);
}
-keep class com.fieldplus.app.worker.** { *; }

# Google Play Services (Location & Code Scanner)
-keep class com.google.android.gms.location.** { *; }
-keep class com.google.android.gms.common.** { *; }
-keep class com.google.android.gms.tasks.** { *; }
-dontwarn com.google.android.gms.**

# ViewBinding
-keep class com.fieldplus.app.databinding.** { *; }

# Services & Map Activities
-keep class com.fieldplus.app.service.** { *; }
-keep class com.fieldplus.app.ui.map.** { *; }
-keepclassmembers class * {
    @android.webkit.JavascriptInterface <methods>;
}
