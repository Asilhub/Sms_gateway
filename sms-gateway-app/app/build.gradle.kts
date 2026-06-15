import java.io.FileInputStream
import java.util.Properties

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

// Maxfiy qiymatlar keys.properties dan o'qiladi (git'ga kirmaydi)
val keysProps = Properties().apply {
    val f = rootProject.file("keys.properties")
    if (f.exists()) FileInputStream(f).use { load(it) }
}
fun keyProp(name: String, default: String = "") = keysProps.getProperty(name, default)

android {
    namespace = "uz.idrokedu.smsgateway"
    compileSdk = 34

    defaultConfig {
        applicationId = "uz.idrokedu.smsgateway"
        minSdk = 24
        targetSdk = 34
        versionCode = 3
        versionName = "0.1.0"

        // API kalit APK ichida saqlanmaydi (foydalanuvchi kiritadi). Faqat SERVER manzili.
        buildConfigField("String", "SERVER", "\"${keyProp("SERVER", "https://sms.idrokedu.uz/webhook.php")}\"")
    }

    buildFeatures {
        buildConfig = true
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"))
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_1_8
        targetCompatibility = JavaVersion.VERSION_1_8
    }

    kotlinOptions {
        jvmTarget = "1.8"
    }
}

dependencies {
    implementation("androidx.core:core-ktx:1.12.0")
    implementation("androidx.appcompat:appcompat:1.6.1")
    implementation("com.google.android.material:material:1.11.0")
    implementation("androidx.cardview:cardview:1.0.0")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.7.3")
    implementation("com.squareup.okhttp3:okhttp:4.12.0")
    implementation("org.json:json:20231013")
}
