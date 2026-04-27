// src/main.js
import { createApp } from 'vue'
import App from './App.vue'
import router from './router' // <--- Importă router-ul

const app = createApp(App)

app.use(router) // <--- Spune-i aplicației să îl folosească

app.mount('#app')