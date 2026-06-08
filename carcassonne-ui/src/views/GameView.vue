<template>
  <div class="page-container game-view">
    <header class="game-header">
      <h1>Joc în Desfășurare</h1>
      <p>ID Joc: {{ $route.params.id }} | Jucător: {{ username }}</p>

      <div class="turn-banner" :class="{ 'my-turn': isMyTurn, 'waiting': gameStatus !== 'in_progress' }">
        <template v-if="gameStatus === 'waiting_for_players'">
          Se așteaptă începerea jocului...
        </template>
        <template v-else-if="gameStatus === 'in_progress' && currentTurnUserId">
          <span class="player-color-dot" :style="{ backgroundColor: playerColor(currentTurnUserId) }"></span>
          <span v-if="isMyTurn"><strong>Este rândul tău!</strong></span>
          <span v-else>Rândul lui <strong>{{ playerNameOf(currentTurnUserId) || '...' }}</strong></span>
        </template>
        <template v-else>
          Jocul nu a început încă.
        </template>
      </div>

      <div class="header-buttons">
        <button
          class="btn-primary"
          style="background-color: #f39c12;"
          @click="handleStartGame"
          :disabled="gameStatus !== 'waiting_for_players'"
        >
          Start Joc (Împarte Cărțile)
        </button>
        <button
          v-if="gameStatus === 'in_progress' && !endGameInfo"
          class="btn-primary"
          style="background-color: #c62828;"
          @click="handleEndGame"
        >
          Termină Jocul Acum
        </button>
        <button class="btn-primary" @click="handleLeave">Ieși în Lobby</button>
      </div>
    </header>

    <main class="game-layout">
      <!-- Players Panel (NEW) -->
      <aside class="players-section">
        <h3>Jucători</h3>
        <ul class="players-list">
          <li 
            v-for="p in players" 
            :key="p.playerId" 
            class="player-row"
            :class="{ 'is-me': p.playerId === userId }"
          >
            <span class="player-color-dot" :style="{ backgroundColor: playerColor(p.playerId) }"></span>
            <span class="player-name">{{ p.playerName }}</span>
            <span class="player-stats">
              🏆 {{ p.score }} &nbsp; 🧍 {{ p.meeplesLeft }}
            </span>
          </li>
        </ul>
      </aside>

      <!-- Current Tile Section -->
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

      <!-- Board -->
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
                <!-- Meeple dot (NEW) - shown only if a meeple was placed and not yet returned -->
                <div 
                  v-if="board[`${x},${y}`].placeMeeple && !board[`${x},${y}`].meeple_returned"
                  class="meeple-dot"
                  :class="`meeple-${board[`${x},${y}`].meepleLocation}`"
                  :style="{ backgroundColor: playerColor(board[`${x},${y}`].userId) }"
                  :title="'Meeple of ' + (playerNameOf(board[`${x},${y}`].userId) || 'unknown')"
                ></div>
              </div>
            </div>
          </div>
        </div>
      </section>
    </main>

    <!-- MEEPLE PLACEMENT MODAL (NEW) -->
    <div v-if="showMeepleModal" class="modal-overlay" @click.self="cancelMeepleChoice">
      <div class="modal-content">
        <h2>Plasează un Meeple?</h2>
        <p v-if="myMeeplesLeft > 0" class="meeple-counter">
          Ai <strong>{{ myMeeplesLeft }}</strong> meeple rămași.
        </p>
        <p v-else class="meeple-counter no-meeples">
          Nu mai ai meeple disponibili!
        </p>

        <!-- Preview of the tile being placed -->
        <div 
          v-if="pendingMove" 
          class="modal-tile-preview"
          :style="{ transform: `rotate(${pendingMove.tileData.rotation}deg)` }"
        >
          <img :src="getImageUrl(pendingMove.tileData.tileId)" class="tile-image" />
        </div>

        <!-- Meeple placement options laid out compass-style -->
        <div class="meeple-options">
          <div class="meeple-row">
            <button 
              class="meeple-btn meeple-pos-n" 
              :disabled="myMeeplesLeft <= 0"
              @click="confirmMove('n')"
            >Sus</button>
          </div>
          <div class="meeple-row">
            <button 
              class="meeple-btn meeple-pos-w" 
              :disabled="myMeeplesLeft <= 0"
              @click="confirmMove('w')"
            >Stânga</button>
            <button 
              class="meeple-btn meeple-pos-c" 
              :disabled="myMeeplesLeft <= 0"
              @click="confirmMove('c')"
            >Centru</button>
            <button 
              class="meeple-btn meeple-pos-e" 
              :disabled="myMeeplesLeft <= 0"
              @click="confirmMove('e')"
            >Dreapta</button>
          </div>
          <div class="meeple-row">
            <button 
              class="meeple-btn meeple-pos-s" 
              :disabled="myMeeplesLeft <= 0"
              @click="confirmMove('s')"
            >Jos</button>
          </div>
        </div>

        <div class="modal-actions">
          <button class="btn-skip" @click="confirmMove(null)">
            Sari peste (fără meeple)
          </button>
        </div>
      </div>
    </div>

    <!-- SCORING TOASTS -->
    <div class="toast-stack" v-if="scoreToasts.length">
      <div
        v-for="t in scoreToasts"
        :key="t.id"
        class="score-toast"
      >
        🏆 {{ t.text }}
      </div>
    </div>

    <!-- END-GAME MODAL -->
    <div v-if="endGameInfo" class="modal-overlay">
      <div class="modal-content end-modal">
        <h2>Jocul s-a terminat!</h2>
        <p class="end-reason">{{ endReasonText }}</p>

        <p v-if="winnerName" class="winner-line">
          🏆 Câștigător: <strong>{{ winnerName }}</strong>
        </p>
        <p v-else-if="endGameInfo.finalScores && endGameInfo.finalScores.length" class="winner-line">
          Egalitate — fără un singur câștigător.
        </p>

        <h3 v-if="endGameInfo.finalScores && endGameInfo.finalScores.length">Scoruri finale</h3>
        <ol class="final-scores" v-if="endGameInfo.finalScores && endGameInfo.finalScores.length">
          <li
            v-for="p in endGameInfo.finalScores"
            :key="p.playerId"
            :class="{ 'winner-row': p.playerId === endGameInfo.winnerId }"
          >
            <span class="player-color-dot" :style="{ backgroundColor: playerColor(p.playerId) }"></span>
            <span class="final-name">{{ p.playerName }}</span>
            <span class="final-score">{{ p.score }} pct</span>
          </li>
        </ol>

        <div class="modal-actions">
          <button class="btn-primary" @click="router.push('/lobby')">
            Înapoi la Lobby
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '../services/api'; 
import Pusher from 'pusher-js';
import { checkEdgeMatch } from '../tileEdges';

const route = useRoute();
const router = useRouter();

const userId = localStorage.getItem('userId') || '';
const username = localStorage.getItem('username') || '';

const board = reactive({});
const currentTile = ref(null);

// --- NEW: meeple modal state ---
const showMeepleModal = ref(false);
const pendingMove = ref(null); // { x, y, tileData } while waiting for player's meeple choice
const players = ref([]);       // list of players with score & meeplesLeft

// --- NEW: shared game state used for the turn indicator ---
const currentTurnUserId = ref(null);
const gameStatus = ref(null);
const isMyTurn = computed(() => currentTurnUserId.value === userId);

// --- NEW: end-game modal payload (null while game is live) ---
const endGameInfo = ref(null);

// --- NEW: transient toasts for scoring events from `move-played` ---
const scoreToasts = ref([]); // [{ id, text }]
let toastSeq = 0;

// Pusher client + channel — declared here so onBeforeUnmount can tear them down.
let pusher = null;
let channel = null;

const FEATURE_LABEL = {
  monastery: 'Mânăstire completă',
  monastery_incomplete: 'Mânăstire (final)',
  edge_cluster: 'Grup (final)',
};

const pushScoreToast = (event) => {
  const name = playerNameOf(event.userId) || 'Cineva';
  const label = FEATURE_LABEL[event.feature] || event.feature;
  const id = ++toastSeq;
  scoreToasts.value.push({
    id,
    text: `${name} +${event.points} (${label})`
  });
  // Auto-dismiss after 4s.
  setTimeout(() => {
    scoreToasts.value = scoreToasts.value.filter(t => t.id !== id);
  }, 4000);
};

const END_REASON_TEXT = {
  deck_exhausted:   'Toate piesele au fost plasate.',
  players_left:     'Prea puțini jucători au rămas pentru a continua.',
  manual:           'Jocul a fost încheiat manual de un jucător.',
  lobby_abandoned:  'Lobby-ul a fost abandonat.'
};

const endReasonText = computed(() =>
  endGameInfo.value ? (END_REASON_TEXT[endGameInfo.value.reason] || 'Joc terminat.') : ''
);

const winnerName = computed(() => {
  if (!endGameInfo.value || !endGameInfo.value.winnerId) return null;
  const w = (endGameInfo.value.finalScores || []).find(p => p.playerId === endGameInfo.value.winnerId);
  return w ? w.playerName : null;
});

// Color palette by join order (max 5 players)
const PLAYER_COLORS = ['#e53935', '#1e88e5', '#43a047', '#fdd835', '#8e24aa'];

const playerColor = (uid) => {
  const idx = players.value.findIndex(p => p.playerId === uid);
  return idx >= 0 ? PLAYER_COLORS[idx % PLAYER_COLORS.length] : '#555';
};

const playerNameOf = (uid) => {
  const p = players.value.find(pl => pl.playerId === uid);
  return p ? p.playerName : null;
};

const myMeeplesLeft = computed(() => {
  const me = players.value.find(p => p.playerId === userId);
  return me ? Number(me.meeplesLeft) : 0;
});

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
      board[`${move.x},${move.y}`] = { 
        tileId: move.tile_type, 
        rotation: move.rotation,
        placeMeeple: !!Number(move.placeMeeple),
        meepleLocation: move.meepleLocation,
        meeple_returned: !!Number(move.meeple_returned),
        userId: move.userId
      };
    });
  } catch (error) {
    console.warn("Board fetch warning:", error.message);
  }
};

const loadPlayers = async () => {
  try {
    players.value = await api.fetchPlayers(route.params.id);
  } catch (error) {
    console.warn("Players fetch warning:", error.message);
  }
};

const loadGameState = async () => {
  try {
    const state = await api.fetchGameState(route.params.id);
    gameStatus.value = state.status;
    currentTurnUserId.value = state.current_turn_userId;
  } catch (error) {
    console.warn("Game state fetch warning:", error.message);
  }
};

const handleStartGame = async () => {
  try {
    // The pusher `game-started` event will update every client (including this one).
    await api.startGame(route.params.id);
  } catch (error) {
    alert(`Eroare: ${error.message}`);
  }
};

const handleLeave = async () => {
  // Best effort: tell the server we're leaving, then go to the lobby.
  // Errors are swallowed because the user-intent is "get me out".
  try {
    await api.leaveGame(route.params.id, userId);
  } catch (error) {
    console.warn("Leave game warning:", error.message);
  }
  router.push('/lobby');
};

const handleEndGame = async () => {
  if (!confirm('Sigur vrei să închei jocul pentru toți jucătorii?')) return;
  try {
    // Server broadcasts `game-ended` to everyone (including us) — modal opens via that.
    await api.endGame(route.params.id, userId);
  } catch (error) {
    alert(`Eroare: ${error.message}`);
  }
};

const handleDrawTile = async () => {
  // Only the active player should ever request a tile.
  if (!isMyTurn.value) return;
  if (currentTile.value) return; // already holding one
  try {
    const tileData = await api.drawTile(route.params.id, userId);
    currentTile.value = { tileId: tileData.tileType, rotation: 0 };
  } catch (error) {
    console.warn("Nu am putut trage piesa:", error.message);
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

const hasAdjacentTile = (x, y) => {
  if (Object.keys(board).length === 0) return true; // prima piesă
  const neighbors = [
    `${x},${y - 1}`, // sus
    `${x},${y + 1}`, // jos
    `${x - 1},${y}`, // stânga
    `${x + 1},${y}`, // dreapta
  ];
  return neighbors.some((key) => board[key]);
};

// NEW: drop no longer submits immediately — it opens the meeple modal
const onDrop = (event, x, y) => {
  if (board[`${x},${y}`]) return; 
  if (!currentTile.value) return;

  if (!hasAdjacentTile(x, y)) {
    alert('Piesa trebuie plasată lângă o piesă existentă!');
    return;
  }

  const match = checkEdgeMatch(board, x, y, currentTile.value.tileId, currentTile.value.rotation);
  if (!match.ok) {
    alert(match.reason);
    return;
  }

  let tileData;
  try {
    tileData = JSON.parse(event.dataTransfer.getData('tileData'));
  } catch {
    return;
  }

  pendingMove.value = { x, y, tileData };
  showMeepleModal.value = true;
};

// NEW: called from modal — either submits with meeple or without
const confirmMove = async (meepleLocation) => {
  if (!pendingMove.value) return;
  const { x, y, tileData } = pendingMove.value;

  const payload = {
    userId,
    tileId: tileData.tileId,
    x, y,
    rotation: tileData.rotation,
    placeMeeple: meepleLocation !== null,
    meepleLocation: meepleLocation
  };

  try {
    const result = await api.submitMove(route.params.id, payload);

    // Optimistically update local board (with meeple info)
    board[`${x},${y}`] = {
      ...tileData,
      placeMeeple: meepleLocation !== null,
      meepleLocation: meepleLocation,
      meeple_returned: false,
      userId: userId
    };

    currentTile.value = null;
    showMeepleModal.value = false;
    pendingMove.value = null;

    // Turn has now passed to someone else — update locally so we don't keep
    // looking like the active player until the pusher echo arrives.
    if (result && result.nextTurn) {
      currentTurnUserId.value = result.nextTurn;
    }

    // If our move emptied the deck, the server has already purged the game.
    // Skip the player refresh (would hit a 404) — the `game-ended` event will populate the modal.
    if (result && result.gameOver) return;

    await loadPlayers();   // refresh meeple counts

  } catch (error) {
    alert(`Eroare: ${error.message}`);
    showMeepleModal.value = false;
    pendingMove.value = null;
  }
};

const cancelMeepleChoice = () => {
  // Closing modal without choice = treat like skip
  // (could alternatively cancel and let them re-drop the tile)
  showMeepleModal.value = false;
  pendingMove.value = null;
};

onMounted(async () => {
  if (!userId) {
    router.push('/');
    return;
  }

  await loadGameState();
  await loadPlayers();
  await loadBoardState();
  // If we reconnect mid-game on our own turn and the deck already gave us
  // a tile, drawTile() will replay it; otherwise it draws a new one.
  await handleDrawTile();

  // --- PUSHER LISTENER ---
  pusher = new Pusher(import.meta.env.VITE_PUSHER_KEY, {
    cluster: import.meta.env.VITE_PUSHER_CLUSTER || 'eu'
  });

  channel = pusher.subscribe('game-' + route.params.id);

  channel.bind('player-joined', async function(data) {
    console.log("Un nou jucător s-a alăturat:", data);
    await loadPlayers();
  });

  channel.bind('game-started', async function(data) {
    console.log("Jocul a început!", data);
    gameStatus.value = 'in_progress';
    currentTurnUserId.value = data.firstTurnPlayerId;
    await loadPlayers();
    await handleDrawTile(); // no-op unless it's our turn
  });

  channel.bind('move-played', async function(data) {
    console.log("Adversarul a mutat! Actualizăm tabla...", data);
    if (data && data.nextTurn) {
      currentTurnUserId.value = data.nextTurn;
    }
    await loadBoardState();
    await loadPlayers();   // also refresh other players' meeple counts
    // Surface any in-game scoring (currently: completed monasteries).
    // loadPlayers ran first so playerNameOf() can resolve the user.
    if (data && Array.isArray(data.scoreEvents)) {
      data.scoreEvents.forEach(pushScoreToast);
    }
    await handleDrawTile(); // no-op unless it's now our turn
  });

  channel.bind('player-left', async function(data) {
    console.log("Un jucător a părăsit jocul:", data);
    if (data && data.nextTurn) {
      currentTurnUserId.value = data.nextTurn;
      // Turn may have just passed to us (the leaver might have been the active player).
      currentTile.value = null;
    }
    await loadPlayers();
    await handleDrawTile();
  });

  channel.bind('game-ended', function(data) {
    console.log("Jocul s-a terminat:", data);
    gameStatus.value = 'finished';
    currentTile.value = null;
    currentTurnUserId.value = null;
    endGameInfo.value = data;
    // Don't auto-navigate — the modal lets the user read the final scores first.
  });
});

// Tear down the Pusher subscription so handlers don't accumulate across remounts.
onBeforeUnmount(() => {
  if (channel) {
    channel.unbind_all();
    channel.unsubscribe();
    channel = null;
  }
  if (pusher) {
    pusher.disconnect();
    pusher = null;
  }
});
</script>

<style scoped>
.page-container { background-color: #c8e6c9; min-height: 100vh; text-align: center; padding: 20px; display: flex; flex-direction: column; align-items: center; }
.turn-banner {
  display: inline-flex; align-items: center; gap: 8px;
  margin: 12px auto 0; padding: 8px 16px;
  background: rgba(255,255,255,0.7); border-radius: 20px;
  border: 2px solid #bdbdbd; font-size: 0.95rem;
}
.turn-banner.my-turn { border-color: #2e7d32; background: #e8f5e9; }
.turn-banner.waiting { border-color: #999; color: #555; font-style: italic; }
.header-buttons { display: flex; gap: 10px; justify-content: center; margin-top: 10px; }
.btn-primary:disabled { background-color: #bdbdbd; cursor: not-allowed; }
.btn-primary { background-color: #2e7d32; color: white; border: none; padding: 10px 25px; border-radius: 25px; font-size: 1rem; cursor: pointer; transition: background 0.3s; }
.btn-primary:hover { background-color: #1b5e20; }
.game-layout { display: flex; justify-content: center; gap: 30px; margin-top: 30px; width: 100%; align-items: flex-start; }
.tile-preview { width: 120px; height: 120px; border: 3px solid #2e7d32; margin: 0 auto; cursor: pointer; background: white; display: flex; align-items: center; justify-content: center; position: relative; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
.tile-image { width: 100%; height: 100%; object-fit: cover; pointer-events: none; }
.rotation-indicator { position: absolute; bottom: 5px; right: 5px; background: rgba(255,255,255,0.8); border-radius: 50%; width: 24px; height: 24px; line-height: 24px; font-size: 14px; }
.grid-row { display: flex; }
.grid-cell { width: 70px; height: 70px; border: 1px solid #a5d6a7; display: flex; align-items: center; justify-content: center; background-color: rgba(255, 255, 255, 0.3); }
.placed-tile { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; position: relative; }

/* --- NEW: Players Panel --- */
.players-section { background: rgba(255,255,255,0.6); padding: 15px 20px; border-radius: 10px; min-width: 220px; text-align: left; }
.players-list { list-style: none; padding: 0; margin: 10px 0 0; }
.player-row { display: flex; align-items: center; gap: 8px; padding: 6px 0; border-bottom: 1px dashed #a5d6a7; font-size: 0.9rem; }
.player-row:last-child { border-bottom: none; }
.player-row.is-me { font-weight: bold; }
.player-color-dot { width: 12px; height: 12px; border-radius: 50%; flex-shrink: 0; }
.player-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.player-stats { font-size: 0.85rem; color: #333; }

/* --- NEW: Meeple dot rendered on placed tiles --- */
.meeple-dot {
  position: absolute;
  width: 14px;
  height: 14px;
  border-radius: 50%;
  border: 2px solid white;
  box-shadow: 0 1px 3px rgba(0,0,0,0.4);
  z-index: 2;
}
.meeple-dot.meeple-n { top: 4px; left: 50%; transform: translateX(-50%); }
.meeple-dot.meeple-s { bottom: 4px; left: 50%; transform: translateX(-50%); }
.meeple-dot.meeple-w { left: 4px; top: 50%; transform: translateY(-50%); }
.meeple-dot.meeple-e { right: 4px; top: 50%; transform: translateY(-50%); }
.meeple-dot.meeple-c { top: 50%; left: 50%; transform: translate(-50%, -50%); }

/* --- NEW: Modal --- */
.modal-overlay {
  position: fixed; inset: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex; align-items: center; justify-content: center;
  z-index: 1000;
}
.modal-content {
  background: #f5f5f5;
  padding: 25px 30px;
  border-radius: 15px;
  text-align: center;
  min-width: 320px;
  box-shadow: 0 8px 24px rgba(0,0,0,0.3);
}
.modal-content h2 { margin: 0 0 10px; color: #2e7d32; }
.meeple-counter { margin: 5px 0 15px; color: #444; }
.meeple-counter.no-meeples { color: #c62828; font-weight: bold; }
.modal-tile-preview { width: 100px; height: 100px; margin: 0 auto 20px; border: 2px solid #2e7d32; background: white; }

.meeple-options { display: flex; flex-direction: column; align-items: center; gap: 8px; margin: 10px 0; }
.meeple-row { display: flex; justify-content: center; gap: 8px; }
.meeple-btn {
  background: #4caf50; color: white; border: none;
  padding: 10px 18px; border-radius: 20px; font-size: 0.9rem;
  cursor: pointer; min-width: 90px; transition: background 0.2s;
}
.meeple-btn:hover:not(:disabled) { background: #2e7d32; }
.meeple-btn:disabled { background: #bdbdbd; cursor: not-allowed; }
.meeple-pos-c { background: #f39c12; }
.meeple-pos-c:hover:not(:disabled) { background: #c87f0a; }

.modal-actions { margin-top: 20px; }
.btn-skip {
  background: transparent;
  color: #555;
  border: 1px solid #999;
  padding: 8px 20px;
  border-radius: 20px;
  cursor: pointer;
  font-size: 0.9rem;
}
.btn-skip:hover { background: #e0e0e0; }

/* --- End-game modal --- */
.end-modal { min-width: 360px; max-width: 90vw; }
.end-modal h2 { color: #c62828; }
.end-reason { color: #555; margin-bottom: 12px; }
.winner-line { font-size: 1.1rem; margin: 8px 0 16px; }
.final-scores { list-style: none; padding: 0; margin: 8px 0 18px; text-align: left; }
.final-scores li {
  display: flex; align-items: center; gap: 10px;
  padding: 6px 10px; border-bottom: 1px dashed #ccc;
}
.final-scores li:last-child { border-bottom: none; }
.final-scores .final-name { flex: 1; }
.final-scores .final-score { font-weight: bold; color: #2e7d32; }
.final-scores .winner-row { background: #fff8e1; border-radius: 6px; font-weight: bold; }

/* --- Scoring toasts --- */
.toast-stack {
  position: fixed;
  top: 80px; right: 24px;
  display: flex; flex-direction: column; gap: 8px;
  z-index: 1100;
  pointer-events: none;
}
.score-toast {
  background: #2e7d32; color: white;
  padding: 10px 16px; border-radius: 24px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.25);
  font-size: 0.95rem;
  animation: toast-in 0.25s ease-out;
}
@keyframes toast-in {
  from { opacity: 0; transform: translateX(20px); }
  to   { opacity: 1; transform: translateX(0); }
}
</style>
