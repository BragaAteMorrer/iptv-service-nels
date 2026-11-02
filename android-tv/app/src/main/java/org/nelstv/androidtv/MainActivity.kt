package org.nelstv.androidtv

import android.app.Activity
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.view.KeyEvent
import android.view.View
import android.widget.*
import androidx.media3.common.MediaItem
import androidx.media3.exoplayer.ExoPlayer
import androidx.media3.ui.PlayerView
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import java.util.concurrent.Executors

/** TV prototype: no credentials persisted, pair to a local Nels TV API. */
class MainActivity : Activity() {
    private val executor = Executors.newSingleThreadExecutor()
    private val ui = Handler(Looper.getMainLooper())
    private lateinit var root: LinearLayout
    private lateinit var playerView: PlayerView
    private lateinit var message: TextView
    private var player: ExoPlayer? = null
    private var apiBase = ""
    private var deviceToken: String? = null
    private var pairId: String? = null
    private var channels = mutableListOf<Pair<Long, String>>()
    private var index = 0

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        root = LinearLayout(this).apply { orientation=LinearLayout.VERTICAL; setPadding(28,24,28,20) }
        setContentView(root)
        titleText("NELS TV · Android TV")
        message = TextView(this).apply { text="Configure le serveur Nels TV"; textSize=18f }
        root.addView(message)
        playerView = PlayerView(this).apply { visibility=View.GONE; useController=true }
        root.addView(playerView, LinearLayout.LayoutParams(-1,0,1f))
        val address = EditText(this).apply {
            hint="URL du serveur (ex. http://192.168.1.10:8080)"
            setSingleLine()
            inputType=android.text.InputType.TYPE_CLASS_TEXT or android.text.InputType.TYPE_TEXT_VARIATION_URI
        }
        root.addView(address)
        val button = Button(this).apply { text="Associer la TV" }
        root.addView(button)
        button.setOnClickListener {
            val proposed=address.text.toString().trim().trimEnd('/')
            if (!(proposed.startsWith("http://") || proposed.startsWith("https://"))) {
                message.text="Adresse HTTP(S) invalide"; return@setOnClickListener
            }
            apiBase=proposed
            address.visibility=View.GONE
            button.visibility=View.GONE
            beginPairing()
        }
    }
    private fun titleText(text:String) {
        root.addView(TextView(this).apply { this.text=text; textSize=25f })
    }
    private fun call(method:String, path:String, body:JSONObject?=null, auth:Boolean=false):JSONObject {
        val connection=(URL(apiBase+path).openConnection() as HttpURLConnection)
        connection.requestMethod=method
        connection.connectTimeout=12000
        connection.readTimeout=12000
        if (auth) connection.setRequestProperty("Authorization","Bearer "+(deviceToken ?: ""))
        if (body!=null) {
            connection.doOutput=true
            connection.setRequestProperty("Content-Type","application/json")
            connection.outputStream.use { it.write(body.toString().toByteArray(Charsets.UTF_8)) }
        }
        try {
            val stream=if(connection.responseCode in 200..299) connection.inputStream else connection.errorStream
            val text=stream?.bufferedReader()?.use { it.readText() } ?: "{}"
            if (connection.responseCode !in 200..299) throw IllegalStateException("HTTP ${connection.responseCode}")
            return JSONObject(text)
        } finally { connection.disconnect() }
    }
    private fun beginPairing() = execute {
        val result=call("POST","/api/devices/pair/start",JSONObject().put("name","Nels TV Android"))
        val id=result.getString("pairing_id")
        pairId=id
        ui.post { message.text="Sur ton PC, ouvre devices.html et valide ce code : ${result.getString("code")}" }
        pollPair(id)
    }
    private fun pollPair(id:String) {
        executor.execute {
            try {
                repeat(30) {
                    if (pairId!=id) return@execute
                    val result=call("POST","/api/devices/pair/status",JSONObject().put("pairing_id",id))
                    if(result.optString("status")=="paired") {
                        deviceToken=result.getString("device_token")
                        pairId=null
                        ui.post { message.text="TV associée ! Chargement des chaînes…" }
                        loadChannels()
                        return@execute
                    }
                    Thread.sleep(5000)
                }
                ui.post { message.text="Code expiré. Redémarre l'application pour réessayer." }
            } catch (e:Exception) { ui.post { message.text="Association : ${e.message}" } }
        }
    }
    private fun loadChannels() {
        executor.execute {
            try {
                val result=call("GET","/api/lineup?limit=100",auth=true).getJSONArray("items")
                channels.clear()
                for(i in 0 until result.length()) {
                    val c=result.getJSONObject(i)
                    channels.add(c.getLong("id") to c.getString("name"))
                }
                ui.post {
                    if(channels.isEmpty()) message.text="Aucune chaîne disponible"
                    else { index=0; zap(0) }
                }
            } catch(e:Exception) { ui.post { message.text="Catalogue : ${e.message}" } }
        }
    }
    private fun zap(step:Int) {
        if(channels.isEmpty()) return
        index=(index+step+channels.size)%channels.size
        val channel=channels[index]
        message.text="${index+1} · ${channel.second}"
        execute {
            val data=call("POST","/api/playback/channel/${channel.first}/session",JSONObject(),true)
            val url=apiBase+data.getString("url")
            ui.post {
                if(channels.getOrNull(index)?.first!=channel.first) return@post
                player?.release()
                player=ExoPlayer.Builder(this).build().also {
                    playerView.player=it
                    playerView.visibility=View.VISIBLE
                    it.setMediaItem(MediaItem.fromUri(url))
                    it.prepare()
                    it.play()
                }
            }
        }
    }
    private fun execute(block:()->Unit) {
        executor.execute {
            try{block()}catch(e:Exception){ui.post{message.text="Erreur réseau : ${e.message}"}}
        }
    }
    override fun onKeyDown(keyCode:Int,event:KeyEvent?):Boolean {
        return when(keyCode) {
            KeyEvent.KEYCODE_CHANNEL_UP,KeyEvent.KEYCODE_DPAD_UP -> {zap(1);true}
            KeyEvent.KEYCODE_CHANNEL_DOWN,KeyEvent.KEYCODE_DPAD_DOWN -> {zap(-1);true}
            else -> super.onKeyDown(keyCode,event)
        }
    }
    override fun onDestroy() {
        pairId=null
        player?.release()
        executor.shutdownNow()
        super.onDestroy()
    }
}
