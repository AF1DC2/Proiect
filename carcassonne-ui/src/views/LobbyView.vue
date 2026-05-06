<template>
  <div class="page-container">
    <div class="content">
      <h1>Lobby Jocuri</h1>
      <p>Salut, {{ username }}! Creează un joc sau introdu un ID de joc existent.</p>
      
      <div class="lobby-actions">
        <button class="btn-primary" @click="handleCreateGame" :disabled="isLoading">
          {{ isLoading ? 'Se creează...' : 'Creează Joc Nou' }}
        </button>
      </div>

      <div class="games-list">
        <h3>Alătură-te unui joc:</h3>
        <input v-model="joinGameId" type="text" placeholder="ID Joc..." class="input-field"/>
        <button class="btn-secondary" @click="handleJoinGame" :disabled="isLoading">
          Alătură-te
        </button>
        <p v-if="errorMessage" class="error-msg">{{ errorMessage }}</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import api from '../services/api'; // Import our clean API service

const router = useRouter();

// Match the exact keys we used in the Login/Register script!
const username = ref(localStorage.getItem('username') || '');
const userId = ref(localStorage.getItem('userId') || '');

const joinGameId = ref('');
const isLoading = ref(false);
const errorMessage = ref('');

const handleCreateGame = async () => {
  isLoading.value = true;
  errorMessage.value = '';
  
  try {
    const newGame = await api.createGame();
    // Automatically join the game you just created
    await api.joinGame(newGame.gameId, userId.value);
    // Route to the actual game board (she set this to /game/:id)
    router.push(`/game/${newGame.gameId}`);
  } catch (error) {
    errorMessage.value = error.message;
  } finally {
    isLoading.value = false;
  }
};

const handleJoinGame = async () => {
  if (!joinGameId.value) {
    errorMessage.value = 'Te rog introdu un ID de joc.';
    return;
  }

  isLoading.value = true;
  errorMessage.value = '';

  try {
    await api.joinGame(joinGameId.value, userId.value);
    router.push(`/game/${joinGameId.value}`);
  } catch (error) {
    errorMessage.value = error.message;
  } finally {
    isLoading.value = false;
  }
};

onMounted(() => {
  // Security guard is back ON! If they bypass the login screen, kick them out.
  if (!userId.value) {
    router.push('/');
  }
});
</script>

<style scoped>
/* Her exact styles, untouched */
.page-container { background-color: #c8e6c9; min-height: 100vh; display: flex; flex-direction: column; align-items: center; padding-top: 50px; text-align: center; }
.btn-primary { background-color: #2e7d32; color: white; border: none; padding: 12px 24px; border-radius: 25px; cursor: pointer; }
.btn-secondary { background-color: #4caf50; color: white; border: none; padding: 10px 15px; border-radius: 15px; cursor: pointer; margin-left: 10px; }
.input-field { padding: 10px; border-radius: 15px; border: 1px solid #2e7d32; font-size: 1rem;}
.games-list { margin-top: 40px; background: rgba(255,255,255,0.5); padding: 20px; border-radius: 10px; min-width: 300px; }
.error-msg { color: #d32f2f; margin-top: 15px; font-weight: bold; }
</style>