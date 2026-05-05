<template>
  <div class="page-container">
    <div class="content">
      <h1>Lobby Jocuri</h1>
      <p>Salut, {{ playerName }}! Creează un joc sau introdu un ID de joc existent.</p>
      
      <div class="lobby-actions">
        <button class="btn-primary" @click="createGame">Creează Joc Nou</button>
      </div>

      <div class="games-list">
        <h3>Alătură-te unui joc:</h3>
        <input v-model="joinGameId" type="text" placeholder="ID Joc..." class="input-field"/>
        <button class="btn-secondary" @click="joinGame">Alătură-te</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';

const router = useRouter();
const playerName = localStorage.getItem('playerName') || 'testUser';
const playerId = localStorage.getItem('playerId') || 'id_fals_123';

const API_URL = 'http://localhost/Proiect/backend/public';
const joinGameId = ref('');

const createGame = async () => {
  try {
    const response = await fetch(`${API_URL}/api/games`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' }
    });

    if (response.ok || response.status === 201) {
      const newGame = await response.json();
      // După ce creezi jocul, trebuie să te și alături lui
      await joinGameAction(newGame.gameId);
    } else {
      alert("Eroare la crearea jocului.");
    }
  } catch (error) {
    alert("Nu s-a putut contacta serverul.");
  }
};

const joinGame = () => {
  if(joinGameId.value) {
    joinGameAction(joinGameId.value);
  }
}

const joinGameAction = async (gameId) => {
  try {
    const response = await fetch(`${API_URL}/api/games/${gameId}/players`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ userId: playerId })
    });

    if (response.ok || response.status === 200 || response.status === 409 /* 409 = E deja in joc */) {
      router.push(`/game/${gameId}`);
    } else {
        const errorData = await response.json();
        alert("Eroare: " + errorData.error);
    }
  } catch (error) {
    alert("Nu s-a putut contacta serverul.");
  }
};

onMounted(() => {
  // Am COMENTAT regula care te dădea afară
  /* 
  if (!playerId) {
    router.push('/');
  } else {
  */
    fetchGames();
  // }
});

</script>
<style scoped>
.page-container { background-color: #c8e6c9; min-height: 100vh; display: flex; flex-direction: column; align-items: center; padding-top: 50px; text-align: center; }
.btn-primary { background-color: #2e7d32; color: white; border: none; padding: 12px 24px; border-radius: 25px; cursor: pointer; }
.btn-secondary { background-color: #4caf50; color: white; border: none; padding: 10px 15px; border-radius: 15px; cursor: pointer; margin-left: 10px; }
.input-field { padding: 10px; border-radius: 15px; border: 1px solid #2e7d32; font-size: 1rem;}
.games-list { margin-top: 40px; background: rgba(255,255,255,0.5); padding: 20px; border-radius: 10px; min-width: 300px; }
</style>