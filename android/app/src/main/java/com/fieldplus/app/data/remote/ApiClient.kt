package com.fieldplus.app.data.remote

import android.content.Context
import com.fieldplus.app.util.PreferenceManager
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import java.util.concurrent.TimeUnit

class ApiClient(private val context: Context) {

    private val preferenceManager = PreferenceManager(context)
    private var currentBaseUrl: String = ""
    private var currentToken: String = ""
    private var serviceInstance: FieldPlusApiService? = null

    fun getService(): FieldPlusApiService {
        val targetUrl = preferenceManager.serverUrl
        val targetToken = preferenceManager.apiToken

        if (serviceInstance == null || currentBaseUrl != targetUrl || currentToken != targetToken) {
            currentBaseUrl = targetUrl
            currentToken = targetToken

            val logging = HttpLoggingInterceptor().apply {
                level = HttpLoggingInterceptor.Level.BASIC
            }

            // Auth Interceptor attaching Bearer token if configured
            val authInterceptor = okhttp3.Interceptor { chain ->
                val originalRequest = chain.request()
                val requestBuilder = originalRequest.newBuilder()

                val token = preferenceManager.apiToken
                if (token.isNotBlank()) {
                    requestBuilder.header("Authorization", "Bearer $token")
                }

                chain.proceed(requestBuilder.build())
            }

            val okHttpClient = OkHttpClient.Builder()
                .connectTimeout(60, TimeUnit.SECONDS)
                .readTimeout(180, TimeUnit.SECONDS)
                .writeTimeout(180, TimeUnit.SECONDS)
                .addInterceptor(authInterceptor)
                .addInterceptor(logging)
                .build()

            val retrofit = Retrofit.Builder()
                .baseUrl(targetUrl)
                .client(okHttpClient)
                .addConverterFactory(GsonConverterFactory.create())
                .build()

            serviceInstance = retrofit.create(FieldPlusApiService::class.java)
        }
        return serviceInstance!!
    }
}
