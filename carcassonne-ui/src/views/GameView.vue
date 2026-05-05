<template>
  <div class="page-container game-view">
    <header class="game-header">
      <h1>Joc în Desfășurare</h1>
      <p>ID Joc: {{ $route.params.id }} | Jucător: {{ playerName }}</p>
      <button class="btn-primary" @click="$router.push('/lobby')">Ieși în Lobby</button>
    </header>

    <main class="game-layout">
      <!-- Secțiunea Piesei Curente (ceea ce ai în mână) -->
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
        <p>Apasă click pentru rotire ({{ currentTile.rotation }}°)</p>
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
              <!-- Afișează piesa doar dacă există la aceste coordonate -->
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

const route = useRoute();
const router = useRouter();

const API_URL = 'http://localhost/Proiect/backend/public';

// Am pus date false ca să putem testa direct fără login
const playerId = localStorage.getItem('playerId') || 'id_fals_123';
const playerName = localStorage.getItem('playerName') || 'testUser';

const board = reactive({
  '0,0': { tileId: 'tile_01', rotation: 0 } // Piesă simulată pe centrul tablei
});
const currentTile = ref({ tileId: 'tile_01', rotation: 0 }); 

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

const fetchBoardState = async () => {
  try {
    const response = await fetch(`${API_URL}/api/games/${route.params.id}/moves`);
    if (response.ok) {
      const moves = await response.json();
      Object.keys(board).forEach(k => delete board[k]); 
      
      moves.forEach(move => {
        board[`${move.x},${move.y}`] = { tileId: move.tileId, rotation: move.rotation };
      });
    }
  } catch (error) {
    console.error("Eroare la preluarea tablei (Serverul PHP ar putea fi oprit):", error);
  }
};

const rotateTile = () => {
  if(currentTile.value) {
    currentTile.value.rotation = (currentTile.value.rotation + 90) % 360;
  }
};

const onDragStart = (event) => {
  event.dataTransfer.setData('tileData', JSON.stringify(currentTile.value));
};

const onDrop = async (event, x, y) => {
  if (board[`${x},${y}`]) return; 

  const tileData = JSON.parse(event.dataTransfer.getData('tileData'));

  const payload = {
    userId: playerId,
    tileId: tileData.tileId,
    x: x,
    y: y,
    rotation: tileData.rotation
  };

  try {
    const response = await fetch(`${API_URL}/api/games/${route.params.id}/moves`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });

    if (response.ok) {
      board[`${x},${y}`] = { ...tileData };
      console.log("Mutare trimisă cu succes!");
      fetchBoardState(); 
    } else {
      board[`${x},${y}`] = { ...tileData };
      console.log("Notă: Backend-ul a respins sau nu e gata, dar piesa a fost pusă pe ecran pentru testare.");
    }
  } catch (error) {
    board[`${x},${y}`] = { ...tileData };
    console.warn("Eroare de conexiune cu backend-ul, dar continuăm simularea vizuală.");
  }

  // === PARTEA NOUĂ: SCHIMBĂM PIESA DIN MÂNĂ DINAMIC ===
  
  // 1. Alegem un număr aleatoriu între 1 și 100
  const numarRandom = Math.floor(Math.random() * 100) + 1;
  
  // 2. Transformăm numărul în text și îi punem un "0" în față dacă e mai mic de 10 
  // (ex: 5 devine "05", 12 rămâne "12")
  const numarFormatat = String(numarRandom).padStart(2, '0');
  
  // 3. Construim numele piesei (ex: "tile_05")
  const piesaUrmatoare = 'tile_' + numarFormatat;
  
  // O setăm ca fiind "Piesa Curentă"
  currentTile.value = { tileId: piesaUrmatoare, rotation: 0 };
};

onMounted(() => {
  // Am comentat redirecționarea ca să putem testa
  /*
  if (!playerId) {
    router.push('/');
  } else {
  */
    fetchBoardState(); 
  // }
});
</script>

<style scoped>
/* Fundalul verde deschis și alinierea centrală conform cerinței */
.page-container {
  background-color: #c8e6c9; 
  min-height: 100vh;
  text-align: center;
  padding: 20px;
  display: flex;
  flex-direction: column;
  align-items: center;
}

/* Buton verde închis, rotunjit */
.btn-primary {
  background-color: #2e7d32;
  color: white;
  border: none;
  padding: 10px 25px;
  border-radius: 25px;
  font-size: 1rem;
  cursor: pointer;
  transition: background 0.3s;
}

.btn-primary:hover {
  background-color: #1b5e20;
}

.game-layout {
  display: flex;
  justify-content: center;
  gap: 50px;
  margin-top: 30px;
  width: 100%;
}

.tile-preview {
  width: 120px;
  height: 120px;
  border: 3px solid #2e7d32;
  margin: 0 auto;
  cursor: pointer;
  background: white;
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
  box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.tile-image {
  width: 100%;
  height: 100%;
  object-fit: cover;
  pointer-events: none; 
}

.rotation-indicator {
  position: absolute;
  bottom: 5px;
  right: 5px;
  background: rgba(255,255,255,0.8);
  border-radius: 50%;
  width: 24px;
  height: 24px;
  line-height: 24px;
  font-size: 14px;
}

.grid-row { display: flex; }

.grid-cell {
  width: 70px;
  height: 70px;
  border: 1px solid #a5d6a7;
  display: flex;
  align-items: center;
  justify-content: center;
  background-color: rgba(255, 255, 255, 0.3); 
}

.placed-tile {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
}
</style>