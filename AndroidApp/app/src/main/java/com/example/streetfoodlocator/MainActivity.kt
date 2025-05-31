package com.example.streetfoodlocator

import android.Manifest
import android.content.pm.PackageManager
import android.location.Location
import android.os.Bundle
import androidx.appcompat.app.AppCompatActivity
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import android.app.AlertDialog
import android.widget.EditText
import android.view.ViewGroup
import android.widget.LinearLayout
import android.widget.TextView
import android.widget.ScrollView
import android.view.Gravity
import android.graphics.Typeface
import android.text.InputType
import android.content.pm.PackageManager
import com.google.android.gms.location.FusedLocationProviderClient
import com.google.android.gms.location.LocationServices
import com.google.android.gms.maps.CameraUpdateFactory
import com.google.android.gms.maps.GoogleMap
import com.google.android.gms.maps.OnMapReadyCallback
import com.google.android.gms.maps.SupportMapFragment
import com.google.android.gms.maps.model.CircleOptions
import com.google.android.gms.maps.model.LatLng
import com.google.android.gms.maps.model.MarkerOptions
import android.Manifest

class MainActivity : AppCompatActivity(), OnMapReadyCallback {

    private lateinit var mMap: GoogleMap
    private lateinit var fusedLocationClient: FusedLocationProviderClient
    private val locationPermissionCode = 1000

    private val businesses = listOf(
        LatLng(19.4326, -99.1332) to "Tacos El Callejón",
        LatLng(19.4330, -99.1350) to "Arepas La Plaza",
        LatLng(19.4310, -99.1320) to "Churros y Café",
        LatLng(19.4340, -99.1340) to "Elotes y Esquites"
    )

    private val products = mapOf(
        "Tacos El Callejón" to listOf(
            Product("Taco de Pastor", 20),
            Product("Taco de Suadero", 25),
            Product("Taco de Bistec", 22)
        ),
        "Arepas La Plaza" to listOf(
            Product("Arepa con Queso", 30),
            Product("Arepa con Pollo", 35)
        ),
        "Churros y Café" to listOf(
            Product("Churro", 15),
            Product("Café Americano", 25)
        ),
        "Elotes y Esquites" to listOf(
            Product("Elote", 18),
            Product("Esquite", 20)
        )
    )

    private var userBudget: Int = 0

    data class Product(val name: String, val price: Int)

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setTheme(R.style.Theme_StreetFoodLocator)
        setContentView(R.layout.activity_main)

        fusedLocationClient = LocationServices.getFusedLocationProviderClient(this)

        showBudgetInputDialog()

        val mapFragment = supportFragmentManager
            .findFragmentById(R.id.map) as SupportMapFragment
        mapFragment.getMapAsync(this)
    }

    private fun showBudgetInputDialog() {
        val builder = AlertDialog.Builder(this)
        builder.setTitle("¿Cuál es tu presupuesto?")

        val input = EditText(this)
        input.inputType = InputType.TYPE_CLASS_NUMBER
        input.hint = "Ingresa tu presupuesto en pesos"
        input.layoutParams = LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT,
            ViewGroup.LayoutParams.WRAP_CONTENT
        )
        builder.setView(input)

        builder.setPositiveButton("Aceptar") { dialog, _ ->
            val inputText = input.text.toString()
            userBudget = if (inputText.isNotEmpty()) inputText.toInt() else 0
            dialog.dismiss()
            showCombinations()
        }
        builder.setCancelable(false)
        builder.show()
    }

    private fun showCombinations() {
        val combinations = calculateCombinations(userBudget)

        val scrollView = ScrollView(this)
        val layout = LinearLayout(this)
        layout.orientation = LinearLayout.VERTICAL
        layout.setPadding(20, 20, 20, 20)

        if (combinations.isEmpty()) {
            val noResult = TextView(this)
            noResult.text = "No hay combinaciones disponibles para tu presupuesto."
            noResult.setTypeface(null, Typeface.BOLD)
            noResult.gravity = Gravity.CENTER
            layout.addView(noResult)
        } else {
            for ((index, combo) in combinations.withIndex()) {
                val comboText = TextView(this)
                comboText.text = "Combinación ${index + 1}:\n" + combo.joinToString("\n") { "${it.first}: ${it.second.name} - \$${it.second.price}" }
                comboText.setPadding(0, 10, 0, 10)
                layout.addView(comboText)
            }
        }

        scrollView.addView(layout)

        val dialog = AlertDialog.Builder(this)
            .setTitle("Combinaciones dentro de tu presupuesto")
            .setView(scrollView)
            .setPositiveButton("Cerrar") { d, _ -> d.dismiss() }
            .create()
        dialog.show()
    }

    private fun calculateCombinations(budget: Int): List<List<Pair<String, Product>>> {
        val results = mutableListOf<List<Pair<String, Product>>>()

        fun backtrack(
            businessIndex: Int,
            currentCombo: MutableList<Pair<String, Product>>,
            currentSum: Int
        ) {
            if (currentSum > budget) return
            if (businessIndex == businesses.size) {
                if (currentSum <= budget && currentCombo.isNotEmpty()) {
                    results.add(currentCombo.toList())
                }
                return
            }

            val businessName = businesses[businessIndex].second
            val productsList = products[businessName] ?: emptyList()

            for (product in productsList) {
                currentCombo.add(businessName to product)
                backtrack(businessIndex + 1, currentCombo, currentSum + product.price)
                currentCombo.removeAt(currentCombo.size - 1)
            }

            // Also consider skipping this business
            backtrack(businessIndex + 1, currentCombo, currentSum)
        }

        backtrack(0, mutableListOf(), 0)
        return results
    }

    override fun onMapReady(googleMap: GoogleMap) {
        mMap = googleMap
        mMap.uiSettings.isZoomControlsEnabled = true

        // Add markers for businesses
        for ((latLng, name) in businesses) {
            mMap.addMarker(MarkerOptions().position(latLng).title(name))
        }

        // Check location permission
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION)
            == PackageManager.PERMISSION_GRANTED) {
            showUserLocation()
        } else {
            ActivityCompat.requestPermissions(this,
                arrayOf(Manifest.permission.ACCESS_FINE_LOCATION), locationPermissionCode)
        }
    }

    private fun showUserLocation() {
        mMap.isMyLocationEnabled = true
        fusedLocationClient.lastLocation.addOnSuccessListener { location: Location? ->
            location?.let {
                val userLatLng = LatLng(it.latitude, it.longitude)
                mMap.moveCamera(CameraUpdateFactory.newLatLngZoom(userLatLng, 16f))
                mMap.addCircle(
                    CircleOptions()
                        .center(userLatLng)
                        .radius(50.0)
                        .strokeColor(0xFF000000.toInt())
                        .fillColor(0x30000000)
                )
            }
        }
    }

    override fun onRequestPermissionsResult(requestCode: Int, permissions: Array<String>, grantResults: IntArray) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        if (requestCode == locationPermissionCode) {
            if ((grantResults.isNotEmpty() && grantResults[0] == PackageManager.PERMISSION_GRANTED)) {
                showUserLocation()
            }
        }
    }
}
