<template>
  <div class="page-container game-view">
    <header class="game-header">
      <h1>Joc în Desfășurare</h1>
      <p>ID Joc: {{ $route.params.id }} | Jucător: {{ username }}</p>
      
      <div class="header-buttons">
        <!-- New Start Game Button -->
        <button class="btn-primary" style="background-color: #f39c12;" @click="handleStartGame">
          Start Joc (Împarte Cărțile)
        </button>
        <button class="btn-primary" @click="$router.push('/lobby')">Ieși în Lobby</button>
      </div>
    </header>

    <main class="game-layout">
      <!-- Secțiunea Piesei Curente -->
      <section class="hand-section">
        <h3>Piesa Curentă</h3>
        <div 
          v-if="currentTile"
          class="tile-preview"
          :style="{ transform: `rotate(${currentTile.rotation}deg)` }"
          @click="rotateTile"
          draggable="true"
          @dragstart="onDragStart"
        >
          <img :src="getImageUrl(currentTile.tileId)" class="tile-image" />
          <div class="rotation-indicator">↻</div>
        </div>
        <p v-if="currentTile">Apasă click pentru rotire ({{ currentTile.rotation }}°)</p>
        <p v-else style="color: #666; font-style: italic;">Așteaptă să înceapă jocul sau să vină rândul tău.</p>
      </section>

      <!-- Tabla de Joc Dinamică -->
      <section class="board-section">
        <div class="grid">
          <div v-for="y in gridRange.y" :key="'row-'+y" class="grid-row">
            <div 
              v-for="x in gridRange.x" 
              :key="`cell-${x}-${y}`"
              class="grid-cell"
              @dragover.prevent
              @drop="onDrop($event, x, y)"
            >
              <div 
                v-if="board[`${x},${y}`]" 
                class="placed-tile"
                :style="{ transform: `rotate(${board[`${x},${y}`].rotation}deg)` }"
              >
                <img :src="getImageUrl(board[`${x},${y}`].tileId)" class="tile-image" />
              </div>
            </div>
          </div>
        </div>
      </section>
    </main>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '../services/api'; 
import Pusher from 'pusher-js';

const route = useRoute();
const router = useRouter();

const userId = localStorage.getItem('userId') || '';
const username = localStorage.getItem('username') || '';

const board = reactive({});
const currentTile = ref(null); 

const getImageUrl = (tileId) => {
  return new URL(`../assets/${tileId}.png`, import.meta.url).href;
};

const gridRange = computed(() => {
  const coords = Object.keys(board).map(k => k.split(',').map(Number));
  const xs = coords.length ? coords.map(c => c[0]) : [0];
  const ys = coords.length ? coords.map(c => c[1]) : [0];
  
  const minX = Math.min(...xs) - 1;
  const maxX = Math.max(...xs) + 1;
  const minY = Math.min(...ys) - 1;
  const maxY = Math.max(...ys) + 1;

  return {
    x: Array.from({ length: maxX - minX + 1 }, (_, i) => minX + i),
    y: Array.from({ length: maxY - minY + 1 }, (_, i) => maxY - i)
  };
});

const loadBoardState = async () => {
  try {
    const moves = await api.fetchBoard(route.params.id);
    Object.keys(board).forEach(k => delete board[k]); 
    moves.forEach(move => {
      board[`${move.x},${move.y}`] = { tileId: move.tile_type, rotation: move.rotation };
    });
  } catch (error) {
    console.warn("Board fetch warning:", error.message);
  }
};

const handleStartGame = async () => {
  try {
    await api.startGame(route.params.id);
    alert('Jocul a început! Cărțile au fost amestecate.');
    await handleDrawTile(); // Draw the first tile automatically!
  } catch (error) {
    alert(`Eroare: ${error.message}`);
  }
};

const handleDrawTile = async () => {
  try {
    const tileData = await api.drawTile(route.params.id, userId);
    currentTile.value = { tileId: tileData.tileType, rotation: 0 }; 
  } catch (error) {
    console.warn("Nu e rândul tău sau jocul nu a început încă.");
  }
};

const rotateTile = () => {
  if(currentTile.value) {
    currentTile.value.rotation = (currentTile.value.rotation + 90) % 360;
  }
};

const onDragStart = (event) => {
  if(!currentTile.value) return;
  event.dataTransfer.setData('tileData', JSON.stringify(currentTile.value));
};

const onDrop = async (event, x, y) => {
  if (board[`${x},${y}`]) return; 

  const tileData = JSON.parse(event.dataTransfer.getData('tileData'));
  const payload = { userId: userId, tileId: tileData.tileId, x: x, y: y, rotation: tileData.rotation };

  try {
    await api.submitMove(route.params.id, payload);
    
    board[`${x},${y}`] = { ...tileData };
    currentTile.value = null; 
    await handleDrawTile();

  } catch (error) {
    alert(`Eroare: ${error.message}`);
  }
};

onMounted(async () => {
  if (!userId) {
    router.push('/');
    return;
  }
  
  await loadBoardState();
  await handleDrawTile(); 

  // --- PUSHER LISTENER ---
  // Enable pusher logging to the browser console so you can see it working!
  Pusher.logToConsole = true;

  // Replace with your exact App Key and Cluster
  const pusher = new Pusher('b852cb41209513497088', {
    cluster: 'eu'
  });

  // Subscribe to the specific channel for THIS game
  const channel = pusher.subscribe('game-' + route.params.id);

  // When the PHP backend triggers the 'move-played' event, do this:
  channel.bind('move-played', async function(data) {
      console.log("Adversarul a mutat! Actualizăm tabla...", data);
      
      // 1. Fetch the new board state so the new tile magically appears
      await loadBoardState();
      
      // 2. Try to draw a tile. If it is now our turn, a tile will pop into our hand!
      await handleDrawTile();
  });
});
</script>

<style scoped>
.page-container { background-color: #c8e6c9; min-height: 100vh; text-align: center; padding: 20px; display: flex; flex-direction: column; align-items: center; }
.header-buttons { display: flex; gap: 10px; justify-content: center; margin-top: 10px; }
.btn-primary { background-color: #2e7d32; color: white; border: none; padding: 10px 25px; border-radius: 25px; font-size: 1rem; cursor: pointer; transition: background 0.3s; }
.btn-primary:hover { background-color: #1b5e20; }
.game-layout { display: flex; justify-content: center; gap: 50px; margin-top: 30px; width: 100%; }
.tile-preview { width: 120px; height: 120px; border: 3px solid #2e7d32; margin: 0 auto; cursor: pointer; background: white; display: flex; align-items: center; justify-content: center; position: relative; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
.tile-image { width: 100%; height: 100%; object-fit: cover; pointer-events: none; }
.rotation-indicator { position: absolute; bottom: 5px; right: 5px; background: rgba(255,255,255,0.8); border-radius: 50%; width: 24px; height: 24px; line-height: 24px; font-size: 14px; }
.grid-row { display: flex; }
.grid-cell { width: 70px; height: 70px; border: 1px solid #a5d6a7; display: flex; align-items: center; justify-content: center; background-color: rgba(255, 255, 255, 0.3); }
.placed-tile { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; }
</style>