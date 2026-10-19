// Off-device checks for the plugin's Kotlin. NativePHP compiles src/ into the app itself;
// this build only unit tests the pure policy on the JVM.
plugins {
    kotlin("jvm") version "2.4.21"
}

kotlin {
    sourceSets {
        main {
            kotlin.setSrcDirs(listOf("src"))
            kotlin.include("**/AppGuardPolicy.kt")
        }
        test {
            kotlin.setSrcDirs(listOf("test"))
        }
    }
}

dependencies {
    testImplementation(kotlin("test-junit"))
}
