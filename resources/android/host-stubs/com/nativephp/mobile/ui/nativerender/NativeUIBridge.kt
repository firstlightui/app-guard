// Stand-in for NativePHP's NativeUIBridge, used only by the hostCheck compile. The real
// treePublicationId is a Compose MutableLongState; only longValue is read.
package com.nativephp.mobile.ui.nativerender

class NativeUIBridge private constructor() {
    companion object {
        val treePublicationId = PublicationId()
    }
}

class PublicationId {
    var longValue: Long = 0L
}
