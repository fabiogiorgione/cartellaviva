import java.util.Properties

plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

android {
    namespace = "it.cartellaviva.app"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        // TODO: Specify your own unique Application ID (https://developer.android.com/studio/build/application-id.html).
        applicationId = "it.cartellaviva.app"
        // You can update the following values to match your application needs.
        // For more information, see: https://flutter.dev/to/review-gradle-config.
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    // La chiave per firmare le versioni da distribuire: android/key.properties
    // (storeFile, storePassword, keyAlias, keyPassword), fuori dal repository
    // come la chiave stessa. Senza, si firma con la chiave di sviluppo.
    val chiave = Properties()
    val fileChiave = rootProject.file("key.properties")
    if (fileChiave.exists()) {
        fileChiave.inputStream().use { chiave.load(it) }
    }

    signingConfigs {
        if (fileChiave.exists()) {
            create("distribuzione") {
                storeFile = file(chiave.getProperty("storeFile"))
                storePassword = chiave.getProperty("storePassword")
                keyAlias = chiave.getProperty("keyAlias")
                keyPassword = chiave.getProperty("keyPassword")
            }
        }
    }

    buildTypes {
        release {
            signingConfig = if (fileChiave.exists()) {
                signingConfigs.getByName("distribuzione")
            } else {
                logger.warn("android/key.properties mancante: la release è firmata con la chiave di sviluppo.")
                signingConfigs.getByName("debug")
            }
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}
