const API_BASE = import.meta.env.VITE_API_BASE || 'http://localhost:3000/api';

export default {
  // Registers a user and returns their new userId
  async registerUser(username, password) {
    const response = await fetch(`${API_BASE}/users`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username, password })
    });
    
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'API Request Failed');
    return data;
  },
  
  // Logs in an existing user
  async loginUser(username, password) {
    const response = await fetch(`${API_BASE}/users/login`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username, password })
    });
    
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'Autentificare eșuată');
    return data;
  },

  // Creates a new game
  async createGame() {
    const response = await fetch(`${API_BASE}/games`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' }
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'Eroare la crearea jocului');
    return data;
  },

  // Joins an existing game
  async joinGame(gameId, userId) {
    const response = await fetch(`${API_BASE}/games/${gameId}/players`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ userId })
    });
    
    // We allow 409 because that means they are already in the lobby (which is fine, we just let them back in)
    if (!response.ok && response.status !== 409) {
        const errorData = await response.json();
        throw new Error(errorData.error || 'Eroare la alăturare');
    }
    
    return await response.json();
  },

  // Starts the game and shuffles the deck!
  async startGame(gameId) {
    const response = await fetch(`${API_BASE}/games/${gameId}/start`, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' }
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'Eroare la pornirea jocului');
    return data;
  },

  // 1. Draw a tile
  async drawTile(gameId, userId) {
    const response = await fetch(`${API_BASE}/games/${gameId}/turn/draw`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ userId })
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'Nu ai putut trage piesa');
    return data;
  },

  // 2. Fetch the current board state
  async fetchBoard(gameId) {
    const response = await fetch(`${API_BASE}/games/${gameId}/moves`);
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'Eroare la preluarea tablei');
    return data;
  },

  // 3. Submit a move
  async submitMove(gameId, payload) {
    const response = await fetch(`${API_BASE}/games/${gameId}/moves`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'Mutare invalidă');
    return data;
  },

  async fetchPlayers(gameId) {
    const res = await fetch(`${API_BASE}/games/${gameId}/players`);
    if (!res.ok) {
      const errorData = await res.json().catch(() => ({}));
      throw new Error(errorData.error || 'Failed to fetch players');
    }
    return res.json();
  },

  async fetchGameState(gameId) {
    const res = await fetch(`${API_BASE}/games/${gameId}`);
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Failed to fetch game state');
    return data;
  },

  async leaveGame(gameId, userId) {
    const res = await fetch(`${API_BASE}/games/${gameId}/players/${userId}`, {
      method: 'DELETE'
    });
    // 404 means the game was already cleaned up — that's fine, we still consider it "left".
    if (!res.ok && res.status !== 404) {
      const data = await res.json().catch(() => ({}));
      throw new Error(data.error || 'Failed to leave game');
    }
    return res.json().catch(() => ({}));
  },

  async endGame(gameId, userId) {
    const res = await fetch(`${API_BASE}/games/${gameId}/end`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ userId })
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Failed to end game');
    return data;
  }

}