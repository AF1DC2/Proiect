// src/router.js
import { createRouter, createWebHistory } from 'vue-router'
import LoginView from './views/LoginView.vue'
import LobbyView from './views/LobbyView.vue'
import GameView from './views/GameView.vue'

const routes = [
  { path: '/', name: 'Login', component: LoginView },
  { path: '/lobby', name: 'Lobby', component: LobbyView },
  { 
    // :id este un parametru dinamic (ex: /game/123)
    path: '/game/:id', 
    name: 'Game', 
    component: GameView 
  }
]

const router = createRouter({
  // Folosim history mode pentru a nu avea # în URL
  history: createWebHistory(),
  routes
})

export default router