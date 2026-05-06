<template>
  <div class="page-container">
    <div class="content">
      <h1>Carcassonne Light</h1>
      <p>{{ isRegistering ? 'Creează un cont nou pentru a juca.' : 'Autentifică-te pentru a juca.' }}</p>
      
      <div class="form-placeholder">
        <input v-model="username" type="text" placeholder="Nume utilizator" class="input-field" />
        <input v-model="password" type="password" placeholder="Parolă" class="input-field" />

        <button class="btn-primary" @click="handleSubmit" :disabled="isLoading">
          {{ isLoading ? 'Se procesează...' : (isRegistering ? 'Creează Cont' : 'Intră în cont') }}
        </button>

        <!-- Toggle between Login and Register -->
        <p class="toggle-text" @click="toggleMode">
          {{ isRegistering ? 'Ai deja cont? Autentifică-te' : 'Nu ai cont? Înregistrează-te' }}
        </p>

        <p v-if="errorMessage" class="error-msg">{{ errorMessage }}</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../services/api' 

const router = useRouter()

const username = ref('')
const password = ref('')
const isLoading = ref(false)
const errorMessage = ref('')
const isRegistering = ref(false) // Keeps track of what mode we are in

const toggleMode = () => {
  isRegistering.value = !isRegistering.value
  errorMessage.value = '' // Clear errors when switching modes
}

const handleSubmit = async () => {
  if (!username.value || !password.value) {
    errorMessage.value = 'Te rog introdu numele și parola.'
    return
  }

  isLoading.value = true
  errorMessage.value = ''

  try {
    let user;
    
    // Choose the right API call based on the current mode!
    if (isRegistering.value) {
      user = await api.registerUser(username.value, password.value)
    } else {
      user = await api.loginUser(username.value, password.value)
    }

    localStorage.setItem('userId', user.userId)
    localStorage.setItem('username', user.username)

    router.push('/lobby')
  } catch (error) {
    errorMessage.value = error.message 
  } finally {
    isLoading.value = false
  }
}
</script>

<style scoped>
.page-container {
  background-color: #c8e6c9; 
  min-height: 100vh;
  display: flex;
  justify-content: center;
  align-items: center;
  text-align: center;
}

.btn-primary {
  background-color: #2e7d32; 
  color: white;
  border: none;
  padding: 12px 24px;
  border-radius: 25px; 
  font-size: 1rem;
  cursor: pointer;
  transition: background 0.3s;
  width: 100%;
  max-width: 250px;
}

.btn-primary:hover {
  background-color: #1b5e20;
}
.btn-primary:disabled {
  opacity: 0.7;
  cursor: not-allowed;
}

.input-field {
  display: block;
  margin: 10px auto;
  padding: 10px;
  border-radius: 5px;
  border: 1px solid #ccc;
  width: 100%;
  max-width: 250px;
  box-sizing: border-box;
}

.toggle-text {
  margin-top: 15px;
  font-size: 0.9rem;
  color: #2e7d32;
  text-decoration: underline;
  cursor: pointer;
}

.toggle-text:hover {
  color: #1b5e20;
}

.error-msg {
  color: #d32f2f;
  margin-top: 15px;
  font-weight: bold;
}
</style>