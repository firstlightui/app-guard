// Off-device checks for the plugin's Kotlin. NativePHP compiles src/ into the app itself;
// this build unit tests the pure policy on the JVM and typechecks every source against android.jar.
plugins {
    kotlin("jvm") version "2.4.21"
}

val androidSdk = System.getenv("ANDROID_HOME")
    ?: System.getenv("ANDROID_SDK_ROOT")
    ?: "${System.getProperty("user.home")}/Library/Android/sdk"

val androidJar = file("$androidSdk/platforms/android-36/android.jar")

val hostCheck by sourceSets.creating

kotlin {
    sourceSets {
        main {
            kotlin.setSrcDirs(listOf("src"))
            kotlin.include("**/AppGuardPolicy.kt")
        }
        test {
            kotlin.setSrcDirs(listOf("test"))
        }
        getByName("hostCheck") {
            kotlin.setSrcDirs(listOf("src", "host-stubs"))
        }
    }
}

dependencies {
    testImplementation(kotlin("test-junit"))
    "hostCheckCompileOnly"(files(androidJar))
}
